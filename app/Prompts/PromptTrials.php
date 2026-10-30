<?php

namespace App\Prompts;

use App\Budgets\Budgets;
use App\Copy\CopyPrompt;
use App\Copy\CopyWriter;
use App\Images\ImageFiles;
use App\Images\ImageGenerator;
use App\Images\ImageModels;
use App\Images\ImageRequest;
use App\Images\ImageSize;
use App\Jobs\TryPrompt;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\ImageModel;
use App\Models\LlmModel;
use App\Models\Output;
use App\Models\Placement;
use App\Models\ProductVariant;
use App\Models\PromptRevision;
use App\Models\Review;
use App\Models\Spend;
use App\Models\User;
use App\Platforms\PlatformLibrary;
use App\Requests\Changes;
use App\Requests\Planner;
use App\Requests\PlannerPrompt;
use App\Review\Reviewer;
use App\Review\ReviewPrompt;
use App\Settings\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * "Try it" on the Prompts page: runs the text in the editor, unsaved, on a
 * sample, through the same code a run uses, and shows what came back. The
 * planner reads a sample brief; the images prompt makes one small picture;
 * the copy prompt writes for one placement; a review prompt scores the
 * brand's latest output beside the saved review; the changes prompt turns
 * a sample change into an instruction. Each try is a provider call,
 * recorded as spend of kind "try" against the person's daily test budget.
 * Nothing is saved: the result lives in the cache for an hour and a tried
 * image on the disk for a day.
 */
class PromptTrials
{
    public const KIND = 'try';

    public const SETTING = 'prompts.try_daily';

    /** Where tried images go on the files disk, under the brand's id. */
    public const DIR = 'prompts/tries';

    public const SAMPLE_BRIEF = 'Marketing image for a sale on whey protein isolate, 20% off this weekend with the code WHEY20.';

    public const SAMPLE_SCENE = 'A tub of whey protein isolate on a bright kitchen counter in morning light, a shaker and a bowl of oats beside it, clean and appetising, with room above for a headline.';

    public const SAMPLE_CHANGE = 'Make the background darker and moodier.';

    private const TTL = 3600;

    public function __construct(
        private Prompts $prompts,
        private Planner $planner,
        private PlannerPrompt $plannerPrompt,
        private CopyWriter $writer,
        private CopyPrompt $copyPrompt,
        private Reviewer $reviewer,
        private ReviewPrompt $reviewPrompt,
        private Changes $changes,
        private ImageGenerator $generator,
        private ImageModels $models,
        private ImageFiles $files,
        private PlatformLibrary $library,
        private Budgets $budgets,
        private Settings $settings,
    ) {}

    /**
     * The sample the page starts with: the brand's latest brief and scene
     * where it has made something, else a stock one.
     *
     * @return array{brief: string, scene: string, change: string}
     */
    public function samples(Brand $brand): array
    {
        $last = CreativeRequest::where('brand_id', $brand->id)->whereNotNull('plan')->latest('id')->first()
            ?? CreativeRequest::where('brand_id', $brand->id)->latest('id')->first();

        return [
            'brief' => trim((string) ($last?->brief ?: self::SAMPLE_BRIEF)),
            'scene' => trim((string) ($last?->plan('image_prompt') ?: self::SAMPLE_SCENE)),
            'change' => self::SAMPLE_CHANGE,
        ];
    }

    /**
     * What a try of this type does and what it works on, for the page.
     *
     * @return array{help: string, output: ?Output, estimate: ?float, placement: ?string}
     */
    public function describe(Brand $brand, PromptType $type): array
    {
        $info = ['help' => '', 'output' => null, 'estimate' => null, 'placement' => null];

        switch ($type) {
            case PromptType::Planner:
                $info['help'] = 'Runs the text above as the planner on the sample brief, with the store to search, and shows the questions it would ask or the plan it would make.';
                break;
            case PromptType::Image:
                $model = $this->imageModel($brand);
                $info['estimate'] = $model ? $this->generator->estimate($model, $this->imageRequest($model, ''), $brand) : null;
                $info['help'] = 'Makes one square image at the smallest size from the sample scene with the rules above'.($model ? ", with {$model->label}" : '').'.';
                break;
            case PromptType::Copy:
                $placement = $this->copyPlacement();
                $info['placement'] = $placement?->label();
                $info['help'] = 'Writes copy from the sample brief with the text above, in English'.($placement ? ", for {$placement->label()}" : '').'.';
                break;
            case PromptType::Change:
                $info['help'] = 'Turns the sample change on the sample scene into the instruction an image model would get, with the text above.';
                break;
            default:
                $info['output'] = $this->latestOutput($brand, $type);
                $info['help'] = 'Scores the latest '.self::outputName($type)." made for {$brand->name} with the text, criteria and hard fails above, beside what the saved review gave it.";
        }

        return $info;
    }

    /**
     * Today's test spend for a person against the daily budget.
     *
     * @return array{limit: float, spent: float, remaining: float}
     */
    public function budget(User $user): array
    {
        $limit = (float) $this->settings->get(self::SETTING);
        $from = CarbonImmutable::now((string) $this->settings->get('platform.timezone'))->startOfDay()->utc();
        $spent = round((float) Spend::where('kind', self::KIND)->where('user_id', $user->id)->where('created_at', '>=', $from)->sum('amount'), 4);

        return ['limit' => $limit, 'spent' => $spent, 'remaining' => max(0, $limit - $spent)];
    }

    /**
     * Why a person can't try a prompt now, or null when they can.
     */
    public function problem(User $user): ?string
    {
        $budget = $this->budget($user);
        if ($budget['limit'] <= 0) {
            return 'Trying prompts is switched off: the test budget per person per day is $0 under Settings → Budgets and spend.';
        }
        if ($budget['remaining'] <= 0) {
            return sprintf('Today\'s $%s test budget for trying prompts is used up; it starts again tomorrow. An admin can change it under Settings → Budgets and spend.', Budgets::money($budget['limit']));
        }
        if ($this->budgets->person($user)['remaining'] <= 0) {
            return 'Your monthly budget is used up, so nothing can be tried until next month. An admin can raise it on Users.';
        }
        $company = $this->budgets->company();
        if ($company['limit'] > 0 && $company['remaining'] <= 0) {
            return 'The company\'s monthly budget is used up, so nothing can be tried until next month or until it\'s raised.';
        }

        return null;
    }

    /**
     * Checks the text and the budget, then queues the try. Returns its token.
     *
     * @param  array{criteria?: list<array<string, mixed>>, hard_fails?: list<array<string, mixed>>}|null  $settings
     * @param  array{brief: string, scene: string, change: string}  $samples
     *
     * @throws ValidationException When the text wouldn't save either.
     * @throws RuntimeException When the budget says no.
     */
    public function start(Brand $brand, PromptType $type, User $by, string $body, ?array $settings, array $samples): string
    {
        $draft = $this->prompts->draft($type, $body, $settings);
        $this->prompts->validate($type, $draft->body, $draft->settings);
        if ($problem = $this->problem($by)) {
            throw new RuntimeException($problem);
        }

        $token = Str::random(32);
        Cache::put(self::key($token), ['status' => 'running', 'brand_id' => $brand->id, 'type' => $type->value], self::TTL);
        TryPrompt::dispatch($token, $brand, $type, $by, $draft->body, $draft->settings, $samples);

        return $token;
    }

    /**
     * Where a try stands: status running, done (with result, cost, model) or failed (with error).
     *
     * @return array<string, mixed>|null
     */
    public function result(string $token, Brand $brand): ?array
    {
        $result = Cache::get(self::key($token));

        return is_array($result) && ($result['brand_id'] ?? null) === $brand->id ? $result : null;
    }

    /**
     * Runs a try and writes its result; the job calls this.
     *
     * @param  array<string, mixed>|null  $settings
     * @param  array{brief: string, scene: string, change: string}  $samples
     */
    public function run(string $token, Brand $brand, PromptType $type, User $by, string $body, ?array $settings, array $samples): void
    {
        $draft = new PromptRevision(['body' => $body, 'settings' => $settings]);
        $spent = 0.0;
        $provider = 'text';
        $model = null;
        $result = null;
        $error = null;

        try {
            [$result, $provider, $model] = match ($type) {
                PromptType::Planner => $this->tryPlanner($brand, $by, $draft, $samples, $spent),
                PromptType::Image => $this->tryImage($token, $brand, $by, $draft, $samples, $spent),
                PromptType::Copy => $this->tryCopy($brand, $by, $draft, $samples, $spent),
                PromptType::Change => $this->tryChange($brand, $by, $draft, $samples, $spent),
                PromptType::ReviewImage, PromptType::ReviewAd => $this->tryReview($brand, $by, $type, $draft, $spent),
            };
        } catch (ValidationException $e) {
            $error = (string) collect($e->errors())->flatten()->first();
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $error = 'Something went wrong while trying it; the log has the detail.';
        } finally {
            if ($spent > 0) {
                $this->budgets->record($provider, self::KIND, $spent, $brand, $by, note: "Tried the {$type->label()} prompt");
            }
        }

        Cache::put(self::key($token), [
            'status' => $error === null ? 'done' : 'failed', 'brand_id' => $brand->id, 'type' => $type->value,
            'error' => $error, 'result' => $result, 'cost' => round($spent, 4), 'model' => $model,
        ], self::TTL);
    }

    /**
     * Marks a try failed from outside the run (the job died).
     */
    public function fail(string $token, Brand $brand, PromptType $type, string $error): void
    {
        Cache::put(self::key($token), ['status' => 'failed', 'brand_id' => $brand->id, 'type' => $type->value, 'error' => $error, 'result' => null, 'cost' => 0, 'model' => null], self::TTL);
    }

    /**
     * The brand's latest output of the kind a review prompt scores, with its file still there.
     */
    public function latestOutput(Brand $brand, PromptType $type): ?Output
    {
        return Output::query()->with(['request.brand', 'review'])
            ->where('kind', $type === PromptType::ReviewAd ? Output::AD : Output::IMAGE)->whereNull('removed_at')
            ->whereHas('request', fn ($q) => $q->where('brand_id', $brand->id))
            ->latest('id')->limit(10)->get()
            ->first(fn (Output $o) => $o->path && $this->files->disk()->exists($o->path));
    }

    /**
     * The address of a tried image for the page.
     */
    public function fileUrl(Brand $brand, string $path): string
    {
        return route('prompts.try-file', [$brand, basename($path)]);
    }

    /**
     * Deletes tried images older than a day; the nightly clean-up calls this.
     */
    public function prune(): int
    {
        $disk = $this->files->disk();
        $cutoff = now()->subDay()->getTimestamp();
        $removed = 0;

        foreach ($disk->allFiles(self::DIR) as $file) {
            if ($disk->lastModified($file) <= $cutoff) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    public static function outputName(PromptType $type): string
    {
        return $type === PromptType::ReviewAd ? 'finished ad' : 'clean image';
    }

    /**
     * @param  array{brief: string, scene: string, change: string}  $samples
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function tryPlanner(Brand $brand, User $by, PromptRevision $draft, array $samples, float &$spent): array
    {
        $models = $this->models->available();
        if ($models->isEmpty()) {
            throw new RuntimeException('No image model is switched on, so the planner has nothing to choose from. An admin can add them on the Models page.');
        }

        $request = $this->sampleRequest($brand, $by, $samples['brief'], 'none');
        $system = $this->plannerPrompt->system($brand, $request, true, $draft);
        $placements = $this->library->available()->groupBy('ratio')->sortKeys()->map->first()->values()->take(3);
        $outcome = $this->planner->think($request, $system, $placements, $models, 1, true, $spent);

        if ($outcome['kind'] === 'questions') {
            $questions = array_map(fn ($q) => ['question' => $q['question'], 'options' => array_column($q['options'], 'label'), 'allow_other' => $q['allow_other']], $this->planner->questions($request, $outcome['input']['questions'] ?? []));

            return [['kind' => 'questions', 'questions' => $questions], ...$this->textModel($brand)];
        }

        $plan = $outcome['input'];
        $variant = isset($plan['product_variant_id'])
            ? ProductVariant::with('product')->whereKey((int) $plan['product_variant_id'])->whereHas('product', fn ($q) => $q->where('brand_id', $brand->id))->first()
            : null;
        $model = $models->first(fn (ImageModel $m) => $m->key() === (string) ($plan['image_model'] ?? ''));
        $details = trim((string) data_get($plan, 'offer.details', ''));

        return [['kind' => 'plan', 'plan' => [
            'title' => trim((string) ($plan['title'] ?? '')),
            'summary' => trim((string) ($plan['summary'] ?? '')),
            'offer' => trim(trim((string) data_get($plan, 'offer.headline', '')).($details !== '' ? " ({$details})" : '')),
            'audience' => trim((string) ($plan['audience'] ?? '')),
            'cta' => trim((string) ($plan['cta'] ?? '')),
            'product' => $variant ? "{$variant->product->title}, {$variant->label()}" : null,
            'product_note' => trim((string) ($plan['product_note'] ?? '')),
            'image_prompt' => trim((string) ($plan['image_prompt'] ?? '')),
            'ratio_notes' => collect($plan['ratio_notes'] ?? [])->map(fn ($n) => trim((string) $n))->filter()->all(),
            'image_model' => $model?->label ?? trim((string) ($plan['image_model'] ?? '')),
            'model_reason' => trim((string) ($plan['model_reason'] ?? '')),
        ]], ...$this->textModel($brand)];
    }

    /**
     * @param  array{brief: string, scene: string, change: string}  $samples
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function tryImage(string $token, Brand $brand, User $by, PromptRevision $draft, array $samples, float &$spent): array
    {
        $model = $this->imageModel($brand) ?? throw new RuntimeException('No image model is switched on. An admin can add them on the Models page.');
        $rules = $this->prompts->resolve(PromptType::Image, $brand, $by, [], 'en', $draft);
        $text = trim($samples['scene'])."\n\n".trim($rules->text);

        try {
            $result = $this->generator->generate($model, $this->imageRequest($model, $text), $brand);
        } catch (ValidationException $e) {
            throw new RuntimeException((string) collect($e->errors())->flatten()->first());
        }
        $spent += $result->cost;

        $image = $result->images[0] ?? throw new RuntimeException("{$model->label} sent back no picture.");
        [$path, $thumb] = $this->files->store(self::DIR."/{$brand->id}", $token, $image);

        return [['prompt' => $text, 'path' => $path, 'thumb' => $thumb, 'width' => $image->width, 'height' => $image->height], $model->provider, $model->label];
    }

    /**
     * @param  array{brief: string, scene: string, change: string}  $samples
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function tryCopy(Brand $brand, User $by, PromptRevision $draft, array $samples, float &$spent): array
    {
        $placement = $this->copyPlacement() ?? throw new RuntimeException('No placement in the platform library takes copy yet. Add the starting platforms under Platform → Platforms first.');
        $limits = $placement->copyLimits();
        $versions = (int) $this->settings->get('copy.versions', $brand->id);
        $request = $this->sampleRequest($brand, $by, $samples['brief'], 'all');
        $system = $this->copyPrompt->system($brand, 'en', $by, $draft);
        $message = $this->copyPrompt->placement($request, $placement, $limits, $versions, 'en');

        $written = $this->writer->compose($brand, $system->text, $message, $limits, $versions, $spent);

        return [['placement' => $placement->label(), 'versions' => $written], ...$this->textModel($brand)];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function tryReview(Brand $brand, User $by, PromptType $type, PromptRevision $draft, float &$spent): array
    {
        $output = $this->latestOutput($brand, $type)
            ?? throw new RuntimeException('Nothing to try it on yet: the latest '.self::outputName($type)." made for {$brand->name} is what gets scored, and there isn't one. Run a request first.");
        $modelName = (string) $this->settings->get('llm.review_model', $brand->id);
        $prompt = $this->reviewPrompt->resolve($brand, $output, $by, $draft);

        $verdict = $this->reviewer->judge($output, $prompt, $modelName, $spent);
        $saved = $output->review;

        return [[
            'output_id' => $output->id,
            'what' => ($output->isAd() ? 'Finished ad' : 'Image')." {$output->ratio} #{$output->position} from \"".($output->request->title ?: Str::limit($output->request->brief, 40)).'"',
            'thumb' => $output->thumbUrl(),
            'scores' => $verdict['scores'],
            'overall' => $verdict['overall'],
            'hard_fails' => array_values(array_intersect_key($prompt->hardFails(), array_flip($verdict['hard_fails']))),
            'reason' => $verdict['reason'],
            'saved' => $saved && $saved->status === Review::DONE ? ['overall' => $saved->overall, 'hard_fails' => count($saved->hard_fails ?? []), 'reason' => $saved->reason] : null,
        ], ...$this->textModel($brand, $modelName)];
    }

    /**
     * @param  array{brief: string, scene: string, change: string}  $samples
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    private function tryChange(Brand $brand, User $by, PromptRevision $draft, array $samples, float &$spent): array
    {
        $model = $this->imageModel($brand);
        $canEdit = $model !== null && $model->edits && $model->max_references > 0;
        $system = $this->prompts->resolve(PromptType::Change, $brand, $by, [], 'en', $draft);
        $rules = $this->prompts->resolve(PromptType::Image, $brand, $by);
        $original = trim($samples['scene']);

        $plan = $this->changes->instruct($brand, $system, $original, [], trim($samples['change']), $canEdit, $original, $spent);
        $made = $this->changes->compose($plan, $canEdit, $original, $rules);

        return [['mode' => $made['mode'], 'instruction' => $made['instruction'], 'keep' => $made['keep'], 'prompt' => $made['text'], 'can_edit' => $canEdit, 'image_model' => $model?->label], ...$this->textModel($brand)];
    }

    /**
     * A request that is never saved, carrying the sample brief for the prompt builders.
     */
    private function sampleRequest(Brand $brand, User $by, string $brief, string $copy): CreativeRequest
    {
        $request = new CreativeRequest([
            'brand_id' => $brand->id, 'user_id' => $by->id, 'status' => CreativeRequest::PLANNING, 'brief' => $brief,
            'languages' => ['en'], 'output' => 'image', 'ad_style' => 'composed', 'copy' => $copy, 'placement_ids' => [], 'rounds' => 0,
        ]);
        $request->setRelation('brand', $brand)->setRelation('user', $by)->setRelation('references', new EloquentCollection)->setRelation('variant', null);

        return $request;
    }

    private function imageModel(Brand $brand): ?ImageModel
    {
        $available = $this->models->available();
        $chosen = (string) $this->settings->get('images.default_model', $brand->id);

        return $available->first(fn (ImageModel $m) => $m->key() === $chosen) ?? $available->first();
    }

    /**
     * One square image at the smallest size the model makes.
     */
    private function imageRequest(ImageModel $model, string $prompt): ImageRequest
    {
        $size = $model->takesSize(ImageSize::DEFAULT) ? ImageSize::DEFAULT : (string) (($model->sizes ?? [])[0] ?? ImageSize::DEFAULT);

        return new ImageRequest($prompt, '1:1', $size, count: 1);
    }

    private function copyPlacement(): ?Placement
    {
        return $this->library->available()->first(fn (Placement $p) => $p->copyLimits() !== []);
    }

    /**
     * The text model's provider (for the spend row) and name (for the page).
     *
     * @return array{0: string, 1: string}
     */
    private function textModel(Brand $brand, ?string $modelName = null): array
    {
        $modelName ??= (string) $this->settings->get('llm.default_model', $brand->id);
        $model = LlmModel::where('model', $modelName)->first();

        return [(string) ($model?->provider ?: 'text'), (string) ($model?->label ?: $modelName)];
    }

    private static function key(string $token): string
    {
        return "prompt-trial:{$token}";
    }
}
