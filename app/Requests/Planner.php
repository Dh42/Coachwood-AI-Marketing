<?php

namespace App\Requests;

use App\Budgets\Budgets;
use App\Catalogue\Catalogue;
use App\Images\ImageGenerator;
use App\Images\ImageModels;
use App\Images\ImageRequest;
use App\Llm\LlmManager;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ToolSpec;
use App\Models\CreativeRequest;
use App\Models\ImageModel;
use App\Models\LlmModel;
use App\Models\Placement;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Platforms\PlatformLibrary;
use App\Prompts\ResolvedPrompt;
use App\Settings\Settings;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Reads a request's brief with the planning model: looks products up in
 * the catalogue, asks the person what it can't work out, and writes the
 * plan (the brief made clear, the image prompt, the model) which the app
 * turns into one target per placement with an estimate. Runs in a job.
 */
class Planner
{
    /** Rounds of tool calls before the planner is told to decide. */
    private const MAX_TURNS = 8;

    public function __construct(
        private LlmManager $llm,
        private PlannerPrompt $prompt,
        private Catalogue $catalogue,
        private PlatformLibrary $library,
        private ImageModels $models,
        private ImageGenerator $generator,
        private Budgets $budgets,
        private Settings $settings,
    ) {}

    public function plan(CreativeRequest $request): void
    {
        $brand = $request->brand;
        $placements = $this->placements($request);
        if ($placements->isEmpty()) {
            throw new RuntimeException('None of the placements on this request is available any more. Edit the request and pick again.');
        }

        $models = $this->models->available();
        if ($models->isEmpty()) {
            throw new RuntimeException('No image model is switched on. An admin can add them on the Models page.');
        }

        $perRatio = $request->per_ratio ?: (int) $this->settings->get('generation.per_ratio', $brand->id);
        $mayAsk = $request->rounds < (int) $this->settings->get('requests.max_rounds');
        $system = $this->prompt->system($brand, $request, $mayAsk);
        $request->update($system->ids());
        $spent = 0.0;

        try {
            $outcome = $this->think($request, $system, $placements, $models, $perRatio, $mayAsk, $spent);
        } finally {
            $this->recordSpend($request, $spent);
        }

        if ($outcome['kind'] === 'questions') {
            $this->ask($request, $outcome['input']['questions'] ?? []);

            return;
        }

        $this->finish($request, $outcome['input'], $placements, $models, $perRatio, $spent);
    }

    /**
     * The conversation with the planning model: the brief, its product
     * searches, and what it decides. Nothing is written; "Try it" on the
     * Prompts page runs this on a sample brief with an unsaved prompt.
     *
     * @param  Collection<int, Placement>  $placements
     * @param  Collection<int, ImageModel>  $models
     * @param  float  $spent  Added to as the calls are made, so it's known even when this throws.
     * @return array{kind: 'questions'|'plan', input: array<string, mixed>}
     *
     * @throws RuntimeException
     */
    public function think(CreativeRequest $request, ResolvedPrompt $system, Collection $placements, Collection $models, int $perRatio, bool $mayAsk, float &$spent): array
    {
        $brand = $request->brand;
        $messages = [['role' => 'user', 'content' => $this->prompt->request($request, $placements, $models, $perRatio)]];
        $tools = $this->tools($mayAsk);

        for ($turn = 0; $turn < self::MAX_TURNS; $turn++) {
            $response = $this->llm->complete(new LlmRequest(
                model: (string) $this->settings->get('llm.default_model', $brand->id),
                system: $system->text,
                messages: $messages,
                tools: $tools,
                effort: (string) $this->settings->get('llm.effort', $brand->id),
                brandId: $brand->id,
            ));
            $spent += $response->cost;

            if ($response->stopReason === LlmResponse::REFUSAL) {
                throw new RuntimeException('The planning model declined this brief.');
            }

            $messages[] = $response->assistantMessage;
            $results = [];

            foreach ($response->toolCalls as $call) {
                if ($call['name'] === 'ask_questions' && $mayAsk) {
                    return ['kind' => 'questions', 'input' => $call['input']];
                }

                if ($call['name'] === 'submit_plan') {
                    return ['kind' => 'plan', 'input' => $call['input']];
                }

                $results[] = ['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => $call['name'] === 'search_products'
                    ? $this->searchProducts($request, (string) ($call['input']['query'] ?? ''))
                    : 'Unknown tool.', 'is_error' => $call['name'] !== 'search_products'];
            }

            if ($response->stopReason !== LlmResponse::TOOL_USE) {
                // It answered in words instead of calling a tool: ask it to decide.
                $messages[] = ['role' => 'user', 'content' => 'Call submit_plan with your plan now'.($mayAsk ? ', or ask_questions if something must be asked first.' : '.')];

                continue;
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        throw new RuntimeException('The planner didn\'t reach a plan. Try again, or make the brief more specific.');
    }

    /**
     * @return Collection<int, Placement>
     */
    public function placements(CreativeRequest $request): Collection
    {
        return $this->library->available()->whereIn('id', $request->placement_ids ?? [])->values();
    }

    /**
     * @return list<ToolSpec>
     */
    private function tools(bool $mayAsk): array
    {
        $tools = [
            new ToolSpec('search_products', 'Finds products in the brand\'s store by words from the brief: title, type, tags, flavour or size. Returns each match with its variants (size and flavour), prices and whether it has a photo.', [
                'type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'A few words naming the product.']], 'required' => ['query'],
            ]),
            new ToolSpec('submit_plan', 'Submits the plan. Call it once, when nothing more needs asking.', [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'A short name for this request, such as "Whey isolate 20% off weekend".'],
                    'summary' => ['type' => 'string', 'description' => 'The brief made clear, in two or three sentences, with any assumptions made.'],
                    'offer' => ['type' => 'object', 'description' => 'The offer, when there is one.', 'properties' => [
                        'headline' => ['type' => 'string', 'description' => 'The offer in a few words, as it would appear on the ad ("20% off whey isolate").'],
                        'details' => ['type' => 'string', 'description' => 'Dates, code, conditions.'],
                    ]],
                    'audience' => ['type' => 'string'],
                    'cta' => ['type' => 'string', 'description' => 'The call to action, from the brand\'s list where one fits.'],
                    'product_variant_id' => ['type' => 'integer', 'description' => 'The chosen variant\'s id from search_products, when the picture shows a product.'],
                    'product_note' => ['type' => 'string', 'description' => 'Why no product, or why this one, in a few words.'],
                    'image_prompt' => ['type' => 'string', 'description' => 'The scene for the image model, in English, following the rules.'],
                    'ratio_notes' => ['type' => 'object', 'description' => 'Framing notes per shape where needed, keyed by the shape ("9:16").', 'additionalProperties' => ['type' => 'string']],
                    'image_model' => ['type' => 'string', 'description' => 'The key of the image model to use, from the list.'],
                    'model_reason' => ['type' => 'string', 'description' => 'Why that model, in one line.'],
                ],
                'required' => ['title', 'summary', 'image_prompt', 'image_model'],
            ]),
        ];

        if ($mayAsk) {
            $tools[] = new ToolSpec('ask_questions', 'Asks the person what you can\'t work out. Use it once per round, with every question for that round.', [
                'type' => 'object',
                'properties' => ['questions' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'description' => 'A short key, such as "product" or "dates".'],
                        'question' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => ['product', 'variant', 'choice', 'text'], 'description' => 'product: pick a product (options are product ids); variant: pick a size or flavour (options are variant ids); choice: pick one option; text: type an answer.'],
                        'options' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['value' => ['type' => 'string'], 'label' => ['type' => 'string']], 'required' => ['value', 'label']]],
                        'allow_other' => ['type' => 'boolean', 'description' => 'Whether the person may type something else instead of an option.'],
                    ],
                    'required' => ['id', 'question', 'type'],
                ]]],
                'required' => ['questions'],
            ]);
        }

        return $tools;
    }

    private function searchProducts(CreativeRequest $request, string $query): string
    {
        $products = $this->catalogue->search($request->brand, $query, 8);

        if ($products->isEmpty()) {
            return 'No product in the store matches those words.';
        }

        return json_encode($products->map(fn (Product $p) => [
            'product_id' => $p->id, 'title' => $p->title, 'type' => $p->type, 'on_the_store' => $p->isLive(), 'tags' => $p->tags,
            'variants' => $p->variants->map(fn (ProductVariant $v) => ['variant_id' => $v->id, 'label' => $v->label(), 'price' => $v->price, 'available' => $v->available, 'has_photo' => $v->product_image_id !== null || $p->images->isNotEmpty()])->values(),
        ])->values(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function ask(CreativeRequest $request, array $questions): void
    {
        $clean = $this->questions($request, $questions);
        if ($clean === []) {
            throw new RuntimeException('The planner asked an empty question.');
        }

        $request->update(['status' => CreativeRequest::ASKING, 'questions' => $clean, 'answers' => null, 'rounds' => $request->rounds + 1, 'error' => null]);
    }

    /**
     * The planner's questions tidied for the page: at most the allowed
     * number, each with a type and options, product and variant choices
     * carrying the store's own labels and pictures.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return list<array{id: string, question: string, type: string, options: list<array<string, mixed>>, allow_other: bool}>
     */
    public function questions(CreativeRequest $request, array $questions): array
    {
        $max = (int) $this->settings->get('requests.max_questions');
        $clean = [];

        foreach (array_slice($questions, 0, $max) as $i => $q) {
            if (blank($q['question'] ?? null)) {
                continue;
            }

            $type = in_array($q['type'] ?? '', ['product', 'variant', 'choice', 'text'], true) ? $q['type'] : 'text';
            $options = collect($q['options'] ?? [])->filter(fn ($o) => filled($o['value'] ?? null) && filled($o['label'] ?? null))
                ->map(fn ($o) => ['value' => (string) $o['value'], 'label' => (string) $o['label']])->values()->all();

            // Product and variant questions carry the store's own options, with the pictures.
            if ($type === 'product') {
                $options = $this->productOptions($request, array_column($options, 'value'));
            } elseif ($type === 'variant') {
                $options = $this->variantOptions($request, array_column($options, 'value'));
            }

            if ($options === [] && $type !== 'text') {
                $type = 'text';
            }

            $clean[] = ['id' => (string) ($q['id'] ?? "q{$i}"), 'question' => trim($q['question']), 'type' => $type, 'options' => $options, 'allow_other' => (bool) ($q['allow_other'] ?? $type === 'text')];
        }

        return $clean;
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{value: string, label: string, image: ?string}>
     */
    private function productOptions(CreativeRequest $request, array $ids): array
    {
        return Product::with('images')->where('brand_id', $request->brand_id)->whereIn('id', $ids)->get()
            ->map(fn (Product $p) => ['value' => (string) $p->id, 'label' => $p->title, 'image' => $p->images->first()?->url])->values()->all();
    }

    /**
     * @param  list<string>  $ids
     * @return list<array{value: string, label: string, image: ?string}>
     */
    private function variantOptions(CreativeRequest $request, array $ids): array
    {
        return ProductVariant::with(['product.images', 'image'])->whereIn('id', $ids)->whereHas('product', fn ($q) => $q->where('brand_id', $request->brand_id))->get()
            ->map(fn (ProductVariant $v) => ['value' => (string) $v->id, 'label' => "{$v->product->title}: {$v->label()}".($v->price ? " (\${$v->price})" : ''), 'image' => $v->image?->url ?? $v->product->images->first()?->url])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  Collection<int, Placement>  $placements
     * @param  Collection<int, ImageModel>  $models
     */
    private function finish(CreativeRequest $request, array $plan, Collection $placements, Collection $models, int $perRatio, float $spent): void
    {
        $modelKey = $request->image_model ?: (string) ($plan['image_model'] ?? '');
        $model = $models->first(fn (ImageModel $m) => $m->key() === $modelKey)
            ?? $models->first(fn (ImageModel $m) => $m->key() === $this->settings->get('images.default_model', $request->brand_id))
            ?? $models->first();

        $variantId = $request->product_variant_id ?: (isset($plan['product_variant_id']) ? (int) $plan['product_variant_id'] : null);
        if ($variantId && ! ProductVariant::where('id', $variantId)->whereHas('product', fn ($q) => $q->where('brand_id', $request->brand_id))->exists()) {
            $variantId = null;
        }

        $size = (string) $this->settings->get('images.default_size', $request->brand_id);
        $request->targets()->delete();
        $imageCost = 0.0;

        foreach ($placements->groupBy('ratio')->sortKeys() as $ratio => $group) {
            $each = $this->generator->estimate($model, new ImageRequest('', $ratio, $size, count: $perRatio), $request->brand) ?? 0.0;
            $imageCost += $each;

            foreach ($group as $i => $placement) {
                $request->targets()->create([
                    'placement_id' => $placement->id, 'ratio' => $ratio, 'output' => $request->output, 'image_model' => $model->key(),
                    'count' => $perRatio, 'estimated_cost' => $i === 0 ? $each : 0,
                ]);
            }
        }

        $allowance = (float) $this->settings->get('budgets.text_allowance', $request->brand_id);

        $request->update([
            'status' => CreativeRequest::PLANNED,
            'title' => mb_substr(trim((string) ($plan['title'] ?? '')) ?: mb_substr($request->brief, 0, 60), 0, 120),
            'product_variant_id' => $variantId,
            'plan' => [
                'summary' => trim((string) ($plan['summary'] ?? '')),
                'offer' => ['headline' => trim((string) data_get($plan, 'offer.headline', '')), 'details' => trim((string) data_get($plan, 'offer.details', ''))],
                'audience' => trim((string) ($plan['audience'] ?? '')),
                'cta' => trim((string) ($plan['cta'] ?? '')),
                'product_note' => trim((string) ($plan['product_note'] ?? '')),
                'image_prompt' => trim((string) ($plan['image_prompt'] ?? '')),
                'ratio_notes' => collect($plan['ratio_notes'] ?? [])->map(fn ($n) => trim((string) $n))->filter()->all(),
                'image_model' => $model->key(),
                'model_reason' => trim((string) ($plan['model_reason'] ?? '')),
                'size' => $size,
                'per_ratio' => $perRatio,
                'planning_cost' => round($spent, 4),
            ],
            'image_cost' => round($imageCost, 4),
            'estimated_cost' => round($imageCost + $allowance, 4),
            'error' => null,
            'planned_at' => now(),
        ]);
    }

    private function recordSpend(CreativeRequest $request, float $spent): void
    {
        if ($spent > 0) {
            $provider = (string) (LlmModel::where('model', $this->settings->get('llm.default_model', $request->brand_id))->value('provider') ?: 'text');
            $this->budgets->record($provider, 'plan', $spent, request: $request, note: 'Planning');
        }
    }
}
