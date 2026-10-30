<?php

namespace App\Requests;

use App\Budgets\Budgets;
use App\Images\ImageGenerator;
use App\Images\ImageRequest;
use App\Images\ReferenceImage;
use App\Jobs\GenerateImage;
use App\Llm\LlmManager;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ToolSpec;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Generation;
use App\Models\LlmModel;
use App\Models\Output;
use App\Models\User;
use App\Notices\Notices;
use App\Prompts\Prompts;
use App\Prompts\PromptType;
use App\Prompts\ResolvedPrompt;
use App\Review\Shortlist;
use App\Settings\Settings;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * After a run: "Make a change" on an image and "Run again" on a shape.
 *
 * A change is always made from the original image, never from an earlier
 * change: the planner folds every change asked so far into one
 * instruction, and sorts it into an edit of the original (local fixes)
 * or the prompt written again (global changes). The result is a new
 * image linked to the one it was asked on, composed, reviewed and judged
 * like any other; the original is never replaced.
 */
class Changes
{
    public const MAX_INSTRUCTION = 500;

    public function __construct(
        private Runs $runs,
        private Prompts $prompts,
        private LlmManager $llm,
        private ImageGenerator $generator,
        private Budgets $budgets,
        private Settings $settings,
    ) {}

    /**
     * Queues a change on an image: one new image, from the original.
     *
     * @throws ValidationException
     */
    public function change(Output $output, string $instruction, User $by): Generation
    {
        $instruction = trim(preg_replace('/\s+/u', ' ', $instruction) ?? '');
        Validator::make(['instruction' => $instruction], ['instruction' => ['required', 'string', 'min:3', 'max:'.self::MAX_INSTRUCTION]], [
            'instruction.required' => 'Say what to change.',
            'instruction.min' => 'Say what to change.',
        ])->validate();

        if ($problem = $this->cannotChange($output)) {
            throw ValidationException::withMessages(['instruction' => $problem]);
        }

        $request = $output->request;
        $original = $output->generation;
        $estimate = $this->estimate($original, 1);
        if ($problem = $this->budgets->problem($by, $estimate)) {
            throw ValidationException::withMessages(['instruction' => $problem]);
        }

        $generation = $request->generations()->create([
            'source_output_id' => $output->id, 'user_id' => $by->id,
            'ratio' => $original->ratio, 'image_model' => $original->image_model, 'size' => $original->size, 'quality' => $original->quality,
            'prompt' => '', 'instruction' => $instruction, 'count' => 1,
        ]);

        activity('requests')->causedBy($by)->performedOn($request)->event('change_asked')->withProperties(['output' => $output->id, 'generation' => $generation->id])
            ->log("Asked for a change on an image of \"{$request->title}\": ".mb_substr($instruction, 0, 80));

        $this->dispatch($generation, [new GenerateImage($generation, 1)]);

        return $generation;
    }

    /**
     * Queues a shape again with the plan unchanged: as many images as the
     * run made for it, with the prompts as they are now.
     *
     * @throws ValidationException
     */
    public function again(Generation $generation, User $by): Generation
    {
        $request = $generation->request;
        if ($problem = $this->cannotRunAgain($generation)) {
            throw ValidationException::withMessages(['generation' => $problem]);
        }

        $target = $request->targets()->where('ratio', $generation->ratio)->first();
        $count = max(1, (int) ($target?->count ?: $generation->count));
        if ($problem = $this->budgets->problem($by, $this->estimate($generation, $count))) {
            throw ValidationException::withMessages(['generation' => $problem]);
        }

        $rules = $this->prompts->resolve(PromptType::Image, $request->brand, $by);
        $again = $request->generations()->create([
            'user_id' => $by->id,
            'ratio' => $generation->ratio, 'image_model' => $generation->image_model, 'size' => $generation->size, 'quality' => $generation->quality,
            'prompt' => $this->runs->prompt($request, $generation->ratio, $rules), ...$rules->ids(), 'count' => $count,
        ]);

        activity('requests')->causedBy($by)->performedOn($request)->event('run_again')->withProperties(['generation' => $again->id, 'count' => $count])
            ->log("Ran the {$generation->ratio} shape of \"{$request->title}\" again: {$count} more ".str('image')->plural($count));

        $jobs = [];
        for ($i = 1; $i <= $count; $i++) {
            $jobs[] = new GenerateImage($again, $i);
        }
        $this->dispatch($again, $jobs);

        return $again;
    }

    /**
     * Why a change can't be asked on an image right now, or null.
     */
    public function cannotChange(Output $output): ?string
    {
        if ($output->kind !== Output::IMAGE) {
            return 'Changes are made on the clean image; the finished ads are made again from it.';
        }
        if ($output->isRemoved() || $output->root()->isRemoved()) {
            return 'The image was rejected and its file has been deleted, so nothing can be made from it.';
        }
        if ($problem = $this->cannotRunAgain($output->generation)) {
            return $problem;
        }
        if ($output->generation->isBusy() || Generation::where('source_output_id', $output->id)->whereIn('status', [Generation::QUEUED, Generation::RUNNING])->exists()) {
            return 'A change on this image is still being made.';
        }
        if ($this->changesLeft($output) <= 0) {
            $max = $this->maxChanges($output->request);

            return "This image has had {$max} ".str('change')->plural($max).'. Start a fresh request with the changes folded into the brief.';
        }

        return null;
    }

    /**
     * Why a shape can't be run again right now, or null.
     */
    public function cannotRunAgain(Generation $generation): ?string
    {
        $request = $generation->request;
        if ($request->status !== CreativeRequest::DONE) {
            return $request->isBusy() ? 'Wait for the run to finish.' : 'This request isn\'t finished, so nothing can be made again yet.';
        }
        if ($request->generations()->where('source_output_id', null)->where('ratio', $generation->ratio)->whereIn('status', [Generation::QUEUED, Generation::RUNNING])->exists()) {
            return 'This shape is still being made.';
        }
        $model = $generation->model();
        if (! $model) {
            return "The model {$generation->image_model} isn't in the list any more.";
        }

        return $this->generator->problem($model, $request->brand);
    }

    /**
     * Changes still allowed on an image's original (the whole chain counts).
     */
    public function changesLeft(Output $output): int
    {
        $root = $output->root();

        return max(0, $this->maxChanges($output->request) - (count($root->tree()) - 1));
    }

    public function maxChanges(CreativeRequest $request): int
    {
        return max(1, (int) $this->settings->get('iterate.max_changes', $request->brand_id));
    }

    /**
     * Every change asked in an image's chain so far, oldest first.
     *
     * @return list<string>
     */
    public function asked(Output $output): array
    {
        $lines = [];
        for ($o = $output; $o->source_id; $o = $o->source) {
            $lines[] = (string) $o->generation->instruction;
        }

        return array_reverse(array_filter($lines));
    }

    /**
     * The image a change is made from and the changes to fold into it: the
     * original when changes may be only one hop from it (the default),
     * else the deepest ancestor within the allowed hops.
     *
     * @return array{0: Output, 1: list<string>}
     */
    public function sourceFor(Output $asked, CreativeRequest $request): array
    {
        $maxDepth = max(1, (int) $this->settings->get('iterate.max_depth', $request->brand_id));
        $source = $asked;
        $fold = [];
        while ($source->depth() > $maxDepth - 1 && $source->source_id) {
            array_unshift($fold, (string) $source->generation->instruction);
            $source = $source->source;
        }

        return [$source, array_values(array_filter($fold))];
    }

    /**
     * The folded brief for a fresh request after the limit: the brief with
     * the changes asked written in.
     */
    public function foldedBrief(Output $output): string
    {
        $asked = $this->asked($output);
        if ($asked === []) {
            foreach ($output->root()->tree() as $version) {
                if ($version->generation->instruction) {
                    $asked[] = $version->generation->instruction;
                }
            }
        }

        return trim($output->request->brief)."\n\nChanges wanted from the last run: ".implode('; ', array_unique($asked)).'.';
    }

    /**
     * Asks the planner how to carry out a change and writes the prompt on
     * the generation. Called by the image job just before generating.
     *
     * @throws RuntimeException
     */
    public function plan(Generation $generation): void
    {
        $request = $generation->request;
        $brand = $request->brand;
        $asked = $generation->source ?? throw new RuntimeException('The image this change was asked on is gone.');
        [$source, $fold] = $this->sourceFor($asked, $request);
        $model = $generation->model() ?? throw new RuntimeException("The model {$generation->image_model} isn't in the list any more.");
        $canEdit = (bool) $model->edits && $model->max_references > 0;
        $user = $generation->user ?? $request->user;

        $system = $this->prompts->resolve(PromptType::Change, $brand, $user);
        $rules = $this->prompts->resolve(PromptType::Image, $brand, $user);
        $earlier = [...$this->asked($source), ...$fold];
        $original = trim($source->root()->generation->prompt);
        $spent = 0.0;

        try {
            $plan = $this->instruct($brand, $system, $original, $earlier, $generation->instruction, $canEdit, trim((string) $request->plan('summary', $request->brief)), $spent);
        } finally {
            if ($spent > 0) {
                $provider = (string) (LlmModel::where('model', $this->settings->get('llm.default_model', $brand->id))->value('provider') ?: 'text');
                $this->budgets->record($provider, 'change', $spent, request: $request, note: 'Planning a change');
            }
        }

        ['mode' => $mode, 'instruction' => $instruction, 'keep' => $keep, 'text' => $text] = $this->compose($plan, $canEdit, $original, $rules);

        $generation->update([
            'prompt' => $text,
            ...$rules->ids(),
            'change' => ['mode' => $mode, 'source_id' => $source->id, 'instruction' => $instruction, 'keep' => $keep, 'folded' => $earlier, 'cost' => round($spent, 5), ...$system->ids()],
        ]);
    }

    /**
     * Asks the planning model how to carry a change out, and returns what
     * it said. Nothing is written; "Try it" on the Prompts page runs this
     * with an unsaved Changes prompt on a sample.
     *
     * @param  list<string>  $earlier  The changes already asked on the image.
     * @param  float  $spent  Added to as the calls are made, so it's known even when this throws.
     * @return array<string, mixed> The plan_change input: mode, instruction, prompt, keep.
     *
     * @throws RuntimeException
     */
    public function instruct(Brand $brand, ResolvedPrompt $system, string $original, array $earlier, string $instruction, bool $canEdit, string $brief, float &$spent): array
    {
        $message = "The prompt the original image was made from:\n{$original}"
            ."\n\nChanges already asked on it".($earlier === [] ? ': none.' : ":\n- ".implode("\n- ", $earlier))
            ."\n\nThe new change: {$instruction}"
            ."\n\nThe image model ".($canEdit ? 'can edit the original image.' : 'can\'t edit an image: write the prompt again.')
            ."\nThe brief, for what must stay true: {$brief}";

        $messages = [['role' => 'user', 'content' => $message]];
        $plan = null;

        for ($turn = 0; $turn < 2 && ! $plan; $turn++) {
            $response = $this->llm->complete(new LlmRequest(
                model: (string) $this->settings->get('llm.default_model', $brand->id),
                system: $system->text,
                messages: $messages,
                tools: [$this->tool($canEdit)],
                maxTokens: 4000,
                effort: (string) $this->settings->get('llm.effort', $brand->id),
                brandId: $brand->id,
            ));
            $spent += $response->cost;
            if ($response->stopReason === LlmResponse::REFUSAL) {
                break;
            }
            foreach ($response->toolCalls as $call) {
                if ($call['name'] === 'plan_change') {
                    $plan = $call['input'];
                }
            }
            $messages[] = $response->assistantMessage;
            $messages[] = ['role' => 'user', 'content' => 'Call plan_change now.'];
        }

        if (! $plan) {
            throw new RuntimeException('The planner couldn\'t turn this change into an instruction. Try saying it another way.');
        }

        return $plan;
    }

    /**
     * The image prompt made from the planner's answer: an edit instruction
     * or the prompt written again, what to keep, then the brand's rules.
     *
     * @param  array<string, mixed>  $plan  What plan_change was called with.
     * @return array{mode: string, instruction: string, keep: list<string>, text: string}
     *
     * @throws RuntimeException
     */
    public function compose(array $plan, bool $canEdit, string $original, ResolvedPrompt $rules): array
    {
        $mode = $canEdit && ($plan['mode'] ?? 'edit') === 'edit' ? 'edit' : 'regenerate';
        $instruction = trim((string) ($plan['instruction'] ?? ''));
        $prompt = trim((string) ($plan['prompt'] ?? ''));
        $keep = array_values(array_filter(array_map(fn ($k) => trim((string) $k), (array) ($plan['keep'] ?? []))));
        if ($mode === 'regenerate' && $prompt === '') {
            $prompt = $original."\n\nChange: {$instruction}";
        }
        if ($mode === 'edit' && $instruction === '') {
            throw new RuntimeException('The planner gave no instruction for the change.');
        }

        $text = $mode === 'edit' ? "Change this image: {$instruction}" : $prompt;
        if ($keep !== []) {
            $text .= "\n\nKeep as it is: ".implode('; ', $keep).'.';
        }
        $text .= "\n\n".trim($rules->text);

        return ['mode' => $mode, 'instruction' => $instruction, 'keep' => $keep, 'text' => $text];
    }

    /**
     * The request to send for a change: an edit sends the source image
     * with the instruction; a regeneration sends the new prompt alone.
     */
    public function imageRequest(Generation $generation): ImageRequest
    {
        $change = $generation->change ?? [];
        $references = [];
        if (($change['mode'] ?? null) === 'edit' && ($source = Output::find($change['source_id'] ?? 0))) {
            $references[] = ReferenceImage::fromDisk($source->path, 'the image to change; keep everything not mentioned exactly as it is');
        }

        return new ImageRequest($generation->prompt, $generation->ratio, $generation->size, $generation->quality, $references, 1);
    }

    /**
     * The last job of a change or a shape run again has finished: its
     * outputs are judged by the same rules as the run's, the request's
     * cost is brought up to date.
     */
    public function finish(?Generation $generation): void
    {
        if (! $generation) {
            return;
        }
        $request = $generation->request;
        if ($generation->isBusy()) {
            $generation->update(['status' => $generation->done > 0 ? Generation::DONE : Generation::FAILED, 'finished_at' => now(), 'error' => $generation->error ?: ($generation->done > 0 ? null : 'Stopped before it finished.')]);
        }

        $shortlist = app(Shortlist::class);
        $images = $generation->outputs()->get();
        foreach ($images as $image) {
            $shortlist->judge($image);
            foreach (Output::where('parent_id', $image->id)->get() as $ad) {
                $shortlist->judge($ad);
            }
        }

        $request->update(['actual_cost' => round((float) $request->generations()->sum('cost') + (float) $request->spend()->where('kind', '!=', 'image')->sum('amount'), 5)]);

        $what = $generation->isChange() ? 'change' : 'run_again';
        $made = $images->count();
        $ok = $images->where('status', Shortlist::SHORTLISTED)->count();
        activity('requests')->causedBy($generation->user)->performedOn($request)->event($made > 0 ? "{$what}_done" : "{$what}_failed")->withProperties(['generation' => $generation->id, 'outputs' => $made, 'cost' => $generation->cost])
            ->log($made > 0
                ? ($generation->isChange() ? "Made the change \"{$generation->instruction}\" on \"{$request->title}\": " : "Ran the {$generation->ratio} shape of \"{$request->title}\" again: ")."{$ok} of {$made} passed, for \$".Budgets::money((float) $generation->cost)
                : ($generation->isChange() ? "The change \"{$generation->instruction}\" on \"{$request->title}\" failed: " : "Running the {$generation->ratio} shape of \"{$request->title}\" again failed: ").$generation->error);

        app(Notices::class)->generationFinished($generation);
    }

    /**
     * US dollars a generation like another is expected to cost: the
     * model's price per image times the number asked for, plus the run's
     * allowance for the text model (the planning and review around it).
     */
    public function estimate(Generation $like, int $count): float
    {
        $model = $like->model();
        $perImage = $model ? ($this->generator->estimate($model, new ImageRequest('', $like->ratio, $like->size, $like->quality), $like->request->brand) ?? 0.0) : 0.0;

        return round($perImage * $count + (float) $this->settings->get('budgets.text_allowance', $like->request->brand_id), 5);
    }

    /**
     * @param  list<GenerateImage>  $jobs
     */
    private function dispatch(Generation $generation, array $jobs): void
    {
        Bus::batch($jobs)
            ->name("Generation {$generation->id} of request {$generation->request_id}")
            ->allowFailures()
            ->finally(fn (Batch $batch) => app(self::class)->finish(Generation::find($generation->id)))
            ->dispatch();
    }

    private function tool(bool $canEdit): ToolSpec
    {
        return new ToolSpec('plan_change', 'Says how the change is carried out: an edit of the original image or the prompt written again, with every change so far folded in.', [
            'type' => 'object',
            'properties' => [
                'mode' => ['type' => 'string', 'enum' => $canEdit ? ['edit', 'regenerate'] : ['regenerate'], 'description' => 'edit: the original image is sent with the instruction. regenerate: the prompt is written again.'],
                'instruction' => ['type' => 'string', 'description' => 'For an edit, the one folded instruction to carry out on the original image. For a regeneration, one line saying what changed.'],
                'prompt' => ['type' => 'string', 'description' => 'For a regeneration, the whole image prompt with the changes worked in. Empty for an edit.'],
                'keep' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What must stay as it is: subject, composition, palette, light, anything not asked to change.'],
            ],
            'required' => ['mode', 'instruction', 'keep'],
        ]);
    }
}
