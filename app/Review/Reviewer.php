<?php

namespace App\Review;

use App\BrandKit\BrandKits;
use App\BrandKit\Kind;
use App\BrandKit\KitFiles;
use App\Budgets\Budgets;
use App\Catalogue\Cutouts;
use App\Images\ImageFiles;
use App\Llm\LlmManager;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ToolSpec;
use App\Models\BrandKitItem;
use App\Models\LlmModel;
use App\Models\Output;
use App\Models\Review;
use App\Prompts\ResolvedPrompt;
use App\Settings\Settings;
use RuntimeException;

/**
 * Scores one output with the review model: it sees the picture (and the
 * real pack shot, for ads), the brief and the checks, and scores each
 * criterion; the app computes the weighted overall and applies hard fails.
 */
class Reviewer
{
    public function __construct(
        private LlmManager $llm,
        private ReviewPrompt $prompt,
        private ImageFiles $files,
        private KitFiles $kitFiles,
        private BrandKits $kits,
        private Budgets $budgets,
        private Settings $settings,
        private Cutouts $cutouts,
    ) {}

    public function review(Output $output): Review
    {
        $request = $output->request;
        $brand = $request->brand;
        $modelName = (string) $this->settings->get('llm.review_model', $brand->id);
        $prompt = $this->prompt->resolve($brand, $output, $request->user);
        $hardFails = $prompt->hardFails();
        $spent = 0.0;

        try {
            ['scores' => $scores, 'overall' => $overall, 'hard_fails' => $fails, 'reason' => $reason] = $this->judge($output, $prompt, $modelName, $spent);
        } catch (RuntimeException $e) {
            $this->spend($output, $modelName, $spent);

            return Review::updateOrCreate(['output_id' => $output->id], ['model' => $modelName, 'scores' => [], 'overall' => null, 'hard_fails' => [], 'reason' => null, 'cost' => round($spent, 5), 'status' => Review::FAILED, 'error' => $e->getMessage(), ...$prompt->ids()]);
        }

        $this->spend($output, $modelName, $spent);
        $review = Review::updateOrCreate(['output_id' => $output->id], ['model' => $modelName, 'scores' => $scores, 'overall' => $overall, 'hard_fails' => $fails, 'reason' => $reason, 'cost' => round($spent, 5), 'status' => Review::DONE, 'error' => null, ...$prompt->ids()]);
        $output->update(['score' => $overall, 'reason' => $fails !== [] ? collect($fails)->map(fn ($f) => $hardFails[$f])->implode('; ').($reason ? " {$reason}" : '') : $reason]);

        return $review;
    }

    /**
     * Shows the review model the output (and the real product beside an
     * ad) and takes its scores; the overall is computed here from the
     * weights. Nothing is written; "Try it" on the Prompts page runs this
     * with an unsaved prompt.
     *
     * @param  float  $spent  Added to as the calls are made, so it's known even when this throws.
     * @return array{scores: list<array{criterion: string, label: string, weight: int, score: int, note: string}>, overall: int, hard_fails: list<string>, reason: string}
     *
     * @throws RuntimeException
     */
    public function judge(Output $output, ResolvedPrompt $prompt, string $modelName, float &$spent): array
    {
        $request = $output->request;
        $brand = $request->brand;
        $criteria = $prompt->criteria();
        $hardFails = $prompt->hardFails();

        $content = [['type' => 'text', 'text' => $this->prompt->message($request, $output)]];
        $content[] = ['type' => 'text', 'text' => $output->isAd() ? 'The finished ad:' : 'The image:'];
        $content[] = $this->imageBlock($this->files->disk()->get($output->path));

        if ($output->isAd() && $request->product_variant_id) {
            $shot = $this->kits->items($brand, Kind::PackShot)->first(fn (BrandKitItem $i) => $i->product_variant_id === $request->product_variant_id && $i->hasFile() && ! $i->isSvg());
            $shotPath = $shot?->path ?? ($request->variant ? $this->cutouts->pathFor($request->variant) : null);
            if ($shotPath) {
                $content[] = ['type' => 'text', 'text' => 'The real product, for comparing the label and packaging:'];
                $content[] = $this->imageBlock($this->kitFiles->disk()->get($shotPath));
            }
        }

        $messages = [['role' => 'user', 'content' => $content]];
        $call = null;

        for ($turn = 0; $turn < 2; $turn++) {
            $response = $this->llm->complete(new LlmRequest($modelName, $prompt->text, $messages, [$this->tool($criteria, $hardFails)], maxTokens: 4000, effort: (string) $this->settings->get('llm.effort', $brand->id), brandId: $brand->id));
            $spent += $response->cost;
            if ($response->stopReason === LlmResponse::REFUSAL) {
                throw new RuntimeException('The review model declined to look at this output.');
            }
            $messages[] = $response->assistantMessage;
            if ($call = collect($response->toolCalls)->firstWhere('name', 'submit_review')) {
                break;
            }
            $messages[] = ['role' => 'user', 'content' => 'Call submit_review with the scores now.'];
        }

        if (! $call) {
            throw new RuntimeException('The review model gave no scores.');
        }

        [$scores, $overall] = $this->score($call['input']['scores'] ?? [], $criteria);

        return [
            'scores' => $scores,
            'overall' => $overall,
            'hard_fails' => array_values(array_intersect(array_keys($hardFails), array_map('strval', (array) ($call['input']['hard_fails'] ?? [])))),
            'reason' => mb_substr(trim((string) ($call['input']['reason'] ?? '')), 0, 300),
        ];
    }

    /**
     * @param  array<string, array{0: string, 1: int, 2: string}>  $criteria
     * @param  array<string, string>  $hardFails
     */
    private function tool(array $criteria, array $hardFails): ToolSpec
    {
        return new ToolSpec('submit_review', 'Submits the scores, the hard fails and the reason.', [
            'type' => 'object',
            'properties' => [
                'scores' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'criterion' => ['type' => 'string', 'enum' => array_keys($criteria)],
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                    'note' => ['type' => 'string', 'description' => 'A few words on why, when the score isn\'t high.'],
                ], 'required' => ['criterion', 'score']]],
                'hard_fails' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys($hardFails) ?: ['none']]],
                'reason' => ['type' => 'string', 'description' => 'One line for the team.'],
            ],
            'required' => ['scores', 'hard_fails', 'reason'],
        ]);
    }

    /**
     * Scores per criterion (a missing one scores 0) and the weighted overall.
     *
     * @param  list<array<string, mixed>>  $given
     * @param  array<string, array{0: string, 1: int, 2: string}>  $criteria
     * @return array{0: list<array{criterion: string, label: string, weight: int, score: int, note: string}>, 1: int}
     */
    private function score(array $given, array $criteria): array
    {
        $byKey = collect($given)->filter(fn ($s) => is_array($s) && isset($criteria[$s['criterion'] ?? '']))->keyBy('criterion');
        $scores = [];
        $sum = 0;
        $weights = 0;

        foreach ($criteria as $key => [$label, $weight]) {
            $score = max(0, min(100, (int) ($byKey[$key]['score'] ?? 0)));
            $scores[] = ['criterion' => $key, 'label' => $label, 'weight' => $weight, 'score' => $score, 'note' => mb_substr(trim((string) ($byKey[$key]['note'] ?? '')), 0, 200)];
            $sum += $score * $weight;
            $weights += $weight;
        }

        return [$scores, $weights > 0 ? (int) round($sum / $weights) : 0];
    }

    /**
     * @return array{type: string, media_type: string, data: string}
     */
    private function imageBlock(string $bytes): array
    {
        $jpeg = $this->files->forReview($bytes, (int) $this->settings->get('review.image_px'));

        return ['type' => 'image', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg)];
    }

    private function spend(Output $output, string $modelName, float $spent): void
    {
        if ($spent > 0) {
            $provider = (string) (LlmModel::where('model', $modelName)->value('provider') ?: 'text');
            $this->budgets->record($provider, 'review', $spent, request: $output->request, note: ($output->isAd() ? 'Ad' : 'Image')." {$output->ratio} #{$output->position}");
        }
    }
}
