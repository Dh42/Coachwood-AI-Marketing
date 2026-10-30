<?php

namespace App\Requests;

use App\Budgets\Budgets;
use App\Jobs\PlanRequest;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Generation;
use App\Models\ImageModel;
use App\Models\Placement;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Making a request and moving it along: plan it, answer the planner,
 * change the brief, confirm within budget, cancel, and public or private.
 */
class Requests
{
    public function __construct(private Budgets $budgets, private Settings $settings, private Runs $runs) {}

    /**
     * Saves the request and sends it to the planner.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(Brand $brand, array $input, User $by): CreativeRequest
    {
        $data = $this->validate($brand, $input);

        $request = CreativeRequest::create([
            'brand_id' => $brand->id, 'user_id' => $by->id, 'status' => CreativeRequest::PLANNING,
            'brief' => trim($data['brief']), 'languages' => array_values(array_intersect(array_keys(CreativeRequest::LANGUAGES), $data['languages'])),
            'output' => $data['output'], 'ad_style' => $data['ad_style'] ?? 'composed', 'copy' => $data['copy'],
            'placement_ids' => array_values(array_map('intval', $data['placement_ids'])),
            'per_ratio' => filled($data['per_ratio'] ?? null) ? (int) $data['per_ratio'] : null,
            'deliver' => filled($data['deliver'] ?? null) ? (int) $data['deliver'] : null,
            'image_model' => $data['image_model'] ?? null,
            'product_variant_id' => filled($data['product_variant_id'] ?? null) ? (int) $data['product_variant_id'] : null,
            'public' => (bool) ($data['public'] ?? true),
        ]);

        activity('requests')->causedBy($by)->performedOn($request)->event('request_created')->log("Asked for creative for {$brand->name}: ".mb_substr($request->brief, 0, 80));

        return $request;
    }

    /**
     * Sends a request (back) to the planner.
     */
    public function plan(CreativeRequest $request): void
    {
        $request->update(['status' => CreativeRequest::PLANNING, 'error' => null]);
        PlanRequest::dispatch($request);
    }

    /**
     * The person's answers to the planner's questions; it plans again with them.
     *
     * @param  array<string, string>  $answers  Question id => the option's value or typed text.
     *
     * @throws ValidationException
     */
    public function answer(CreativeRequest $request, array $answers, User $by): void
    {
        if ($request->status !== CreativeRequest::ASKING) {
            throw ValidationException::withMessages(['answers' => 'This request isn\'t waiting for an answer.']);
        }

        $errors = [];
        $clean = [];
        foreach ($request->questions ?? [] as $q) {
            $answer = trim((string) ($answers[$q['id']] ?? ''));
            $values = array_column($q['options'] ?? [], 'value');

            if ($answer === '') {
                $errors["answers.{$q['id']}"] = 'Choose or type an answer.';
            } elseif ($values !== [] && ! in_array($answer, $values, true) && ! ($q['allow_other'] ?? false) && $answer !== 'any') {
                $errors["answers.{$q['id']}"] = 'Choose one of the options.';
            } else {
                $clean[$q['id']] = mb_substr($answer, 0, 500);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // A chosen variant is the product: the planner doesn't have to find it again.
        $variantId = $request->product_variant_id;
        foreach ($request->questions as $q) {
            if ($q['type'] === 'variant' && ctype_digit($clean[$q['id']] ?? '')) {
                $variantId = (int) $clean[$q['id']];
            }
        }

        $request->update(['answers' => $clean, 'product_variant_id' => $variantId]);
        activity('requests')->causedBy($by)->performedOn($request)->event('request_answered')->log('Answered the planner\'s questions');
        $this->plan($request);
    }

    /**
     * A changed brief or choices: the plan is made again from scratch.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function change(CreativeRequest $request, array $input, User $by): void
    {
        if (! $request->isOpen() && $request->status !== CreativeRequest::FAILED) {
            throw ValidationException::withMessages(['brief' => 'This request can\'t be changed any more.']);
        }

        $data = $this->validate($request->brand, $input);

        $request->update([
            'brief' => trim($data['brief']), 'languages' => array_values(array_intersect(array_keys(CreativeRequest::LANGUAGES), $data['languages'])),
            'output' => $data['output'], 'ad_style' => $data['ad_style'] ?? 'composed', 'copy' => $data['copy'],
            'placement_ids' => array_values(array_map('intval', $data['placement_ids'])),
            'per_ratio' => filled($data['per_ratio'] ?? null) ? (int) $data['per_ratio'] : null,
            'deliver' => filled($data['deliver'] ?? null) ? (int) $data['deliver'] : null,
            'image_model' => $data['image_model'] ?? null,
            'product_variant_id' => filled($data['product_variant_id'] ?? null) ? (int) $data['product_variant_id'] : null,
            'questions' => null, 'answers' => null, 'rounds' => 0, 'plan' => null, 'image_cost' => null, 'estimated_cost' => null, 'planned_at' => null,
        ]);
        $request->targets()->delete();

        activity('requests')->causedBy($by)->performedOn($request)->event('request_changed')->log('Changed the brief');
        $this->plan($request);
    }

    /**
     * Confirms the plan, if the budgets allow it, and starts the run.
     *
     * @throws ValidationException
     */
    public function confirm(CreativeRequest $request, User $by): void
    {
        if ($request->status !== CreativeRequest::PLANNED) {
            throw ValidationException::withMessages(['plan' => 'There\'s no plan to confirm yet.']);
        }

        if ($problem = $this->budgets->problem($by, (float) $request->estimated_cost)) {
            throw ValidationException::withMessages(['budget' => $problem]);
        }

        $request->update(['status' => CreativeRequest::READY, 'confirmed_at' => now()]);
        activity('requests')->causedBy($by)->performedOn($request)->event('request_confirmed')->withProperties(['estimated_cost' => $request->estimated_cost])
            ->log("Confirmed \"{$request->title}\" at an estimated \$".Budgets::money((float) $request->estimated_cost));

        $this->runs->start($request);
    }

    public function cancel(CreativeRequest $request, User $by): void
    {
        if ($request->status === CreativeRequest::RUNNING && $request->batch_id) {
            // The jobs still queued stop; one mid-call finishes and its image is kept.
            Bus::findBatch($request->batch_id)?->cancel();
            $request->generations()->whereIn('status', [Generation::QUEUED, Generation::RUNNING])->update(['status' => Generation::FAILED, 'error' => 'Cancelled.', 'finished_at' => now()]);
        }

        $request->update(['status' => CreativeRequest::CANCELLED, 'finished_at' => $request->confirmed_at ? now() : null]);
        activity('requests')->causedBy($by)->performedOn($request)->event('request_cancelled')->log('Cancelled "'.($request->title ?: mb_substr($request->brief, 0, 60)).'"');
    }

    public function setPublic(CreativeRequest $request, bool $public, User $by): void
    {
        if ($request->public === $public) {
            return;
        }

        $request->update(['public' => $public]);
        activity('requests')->causedBy($by)->performedOn($request)->event($public ? 'request_made_public' : 'request_made_private')->log(($public ? 'Made public: ' : 'Made private: ').($request->title ?: mb_substr($request->brief, 0, 60)));
    }

    /**
     * What's on the plan, in rows for the screen: each shape with its placements, model, count and estimate.
     *
     * @return list<array{ratio: string, placements: list<string>, model: ?ImageModel, count: int, cost: float}>
     */
    public function planRows(CreativeRequest $request): array
    {
        return $request->targets()->with('placement.platform')->get()->groupBy('ratio')->map(fn ($targets, $ratio) => [
            'ratio' => $ratio,
            'placements' => $targets->map(fn ($t) => $t->placement->label().' ('.$t->placement->sizeLabel().')')->all(),
            'model' => $targets->first()->model(),
            'count' => (int) $targets->first()->count,
            'cost' => (float) $targets->sum('estimated_cost'),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(Brand $brand, array $input): array
    {
        $available = Placement::available()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $models = ImageModel::options();

        return Validator::make($input, [
            'brief' => ['required', 'string', 'min:10', 'max:4000'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => [Rule::in(array_keys(CreativeRequest::LANGUAGES))],
            'output' => ['required', Rule::in(array_keys(CreativeRequest::OUTPUTS))],
            'ad_style' => ['nullable', Rule::in(array_keys(CreativeRequest::AD_STYLES))],
            'copy' => ['required', Rule::in(array_keys(CreativeRequest::COPY))],
            'placement_ids' => ['required', 'array', 'min:1'],
            'placement_ids.*' => [Rule::in($available)],
            'per_ratio' => ['nullable', 'integer', 'min:1', 'max:20'],
            'deliver' => ['nullable', 'integer', 'min:1', 'max:20'],
            'image_model' => ['nullable', Rule::in(array_keys($models))],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where(fn ($q) => $q->whereIn('product_id', fn ($p) => $p->select('id')->from('products')->where('brand_id', $brand->id)))],
            'public' => ['boolean'],
        ], [
            'brief.required' => 'Say what you need, in a sentence or two.',
            'brief.min' => 'Say a little more about what you need.',
            'languages.required' => 'Tick at least one language.',
            'languages.min' => 'Tick at least one language.',
            'placement_ids.required' => 'Tick at least one placement.',
            'placement_ids.min' => 'Tick at least one placement.',
            'placement_ids.*.in' => 'One of the placements isn\'t available any more.',
            'image_model.in' => 'That model isn\'t available.',
            'product_variant_id.exists' => 'That product isn\'t in this brand\'s store.',
        ])->validate();
    }
}
