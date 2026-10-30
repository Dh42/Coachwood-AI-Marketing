<?php

namespace App\Copy;

use App\Budgets\Budgets;
use App\Llm\LlmManager;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ToolSpec;
use App\Models\Brand;
use App\Models\Copy;
use App\Models\CreativeRequest;
use App\Models\LlmModel;
use App\Models\Placement;
use App\Platforms\CopyField;
use App\Settings\Settings;
use RuntimeException;

/**
 * Writes copy with the text model: several versions per placement and
 * language to the placement's limits, checked by counting in code and
 * sent back to be shortened when over; and the words on finished ads per
 * language. Runs in a job per placement and language.
 */
class CopyWriter
{
    private const AD_LIMITS = ['headline' => 40, 'subline' => 80, 'cta' => 20];

    public function __construct(private LlmManager $llm, private CopyPrompt $prompt, private Budgets $budgets, private Settings $settings) {}

    /**
     * The pieces a placement takes for a request's copy choice, with their limits.
     *
     * @return array<string, int>
     */
    public static function limitsFor(CreativeRequest $request, Placement $placement): array
    {
        $fields = match ($request->copy) {
            'none' => [],
            'captions' => [CopyField::Caption->value, CopyField::Hashtags->value],
            'ad' => [CopyField::PrimaryText->value, CopyField::Headline->value, CopyField::Description->value],
            default => array_column(CopyField::cases(), 'value'),
        };

        return array_intersect_key($placement->copyLimits(), array_flip($fields));
    }

    /**
     * Copy for one placement in one language.
     */
    public function placement(CreativeRequest $request, Placement $placement, string $language): Copy
    {
        $limits = self::limitsFor($request, $placement);
        if ($limits === []) {
            throw new RuntimeException("{$placement->label()} takes no copy for this request.");
        }

        $versions = (int) $this->settings->get('copy.versions', $request->brand_id);
        $message = $this->prompt->placement($request, $placement, $limits, $versions, $language);

        return $this->write($request, $placement, $language, Copy::PLACEMENT, $message, $limits, $versions);
    }

    /**
     * The words on the finished ads in one language.
     */
    public function ad(CreativeRequest $request, string $language): Copy
    {
        return $this->write($request, null, $language, Copy::AD, $this->prompt->ad($request, $language), self::AD_LIMITS, 1);
    }

    /**
     * @param  array<string, int>  $limits
     */
    private function write(CreativeRequest $request, ?Placement $placement, string $language, string $kind, string $message, array $limits, int $count): Copy
    {
        $brand = $request->brand;
        $modelName = (string) $this->settings->get('llm.default_model', $brand->id);
        $system = $this->prompt->system($brand, $language, $request->user);
        $spent = 0.0;

        try {
            $versions = $this->compose($brand, $system->text, $message, $limits, $count, $spent);
        } catch (RuntimeException $e) {
            $this->spend($request, $modelName, $spent, $placement, $language);

            return Copy::updateOrCreate(['request_id' => $request->id, 'placement_id' => $placement?->id, 'language' => $language, 'kind' => $kind],
                ['versions' => [], 'model' => $modelName, 'cost' => round($spent, 5), 'status' => Copy::FAILED, 'error' => $e->getMessage(), ...$system->ids()]);
        }

        $this->spend($request, $modelName, $spent, $placement, $language);

        return Copy::updateOrCreate(['request_id' => $request->id, 'placement_id' => $placement?->id, 'language' => $language, 'kind' => $kind],
            ['versions' => $versions, 'model' => $modelName, 'cost' => round($spent, 5), 'status' => Copy::DONE, 'error' => null, ...$system->ids()]);
    }

    /**
     * The conversation with the text model: the copy asked for, counted
     * against the limits, sent back to be shortened when over. Nothing is
     * written; "Try it" on the Prompts page runs this with an unsaved prompt.
     *
     * @param  array<string, int>  $limits
     * @param  float  $spent  Added to as the calls are made, so it's known even when this throws.
     * @return list<array<string, mixed>> Each version's pieces with their counts.
     *
     * @throws RuntimeException
     */
    public function compose(Brand $brand, string $system, string $message, array $limits, int $count, float &$spent): array
    {
        $modelName = (string) $this->settings->get('llm.default_model', $brand->id);
        $messages = [['role' => 'user', 'content' => $message]];
        $tool = $this->tool($limits, $count);
        $versions = [];
        $rewrites = (int) $this->settings->get('copy.rewrites');

        for ($turn = 0; $turn <= $rewrites + 1; $turn++) {
            $response = $this->llm->complete(new LlmRequest($modelName, $system, $messages, [$tool], maxTokens: 8000, effort: (string) $this->settings->get('llm.effort', $brand->id), brandId: $brand->id));
            $spent += $response->cost;

            if ($response->stopReason === LlmResponse::REFUSAL) {
                throw new RuntimeException('The text model declined to write this copy.');
            }

            $messages[] = $response->assistantMessage;
            $call = collect($response->toolCalls)->firstWhere('name', 'submit_copy');

            if (! $call) {
                $messages[] = ['role' => 'user', 'content' => 'Call submit_copy with the versions now.'];

                continue;
            }

            $versions = $this->check(array_slice($call['input']['versions'] ?? [], 0, $count), $limits);
            $over = $this->over($versions);

            if ($over === [] || $turn >= $rewrites) {
                break;
            }

            // Tell it exactly what's over, and ask for the same versions shortened.
            $messages[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => $call['id'], 'content' => 'Over the limit: '.implode('; ', $over).'. Shorten those pieces and call submit_copy again with every version.', 'is_error' => true]]];
        }

        if ($versions === []) {
            throw new RuntimeException('The text model sent no copy.');
        }

        return $versions;
    }

    /**
     * @param  array<string, int>  $limits
     */
    private function tool(array $limits, int $count): ToolSpec
    {
        $properties = [];
        foreach ($limits as $field => $limit) {
            $f = CopyField::tryFrom($field);
            $properties[$field] = $f === CopyField::Hashtags
                ? ['type' => 'array', 'items' => ['type' => 'string'], 'description' => "Up to {$limit} hashtags, each starting with #."]
                : ['type' => 'string', 'description' => ($f?->label() ?? ucfirst($field))." of at most {$limit} characters."];
        }

        return new ToolSpec('submit_copy', "Submits the copy: {$count} ".($count === 1 ? 'version' : 'versions').', each with every piece.', [
            'type' => 'object',
            'properties' => ['versions' => ['type' => 'array', 'minItems' => 1, 'maxItems' => $count, 'items' => ['type' => 'object', 'properties' => $properties, 'required' => array_keys($limits)]]],
            'required' => ['versions'],
        ]);
    }

    /**
     * Each version's pieces trimmed and counted against the limits.
     *
     * @param  list<array<string, mixed>>  $versions
     * @param  array<string, int>  $limits
     * @return list<array<string, mixed>>
     */
    private function check(array $versions, array $limits): array
    {
        $out = [];
        foreach ($versions as $version) {
            if (! is_array($version)) {
                continue;
            }
            $clean = [];
            $checks = [];
            foreach ($limits as $field => $limit) {
                $f = CopyField::tryFrom($field);
                $value = $version[$field] ?? '';
                if ($f === CopyField::Hashtags) {
                    $value = collect(is_array($value) ? $value : preg_split('/\s+/', (string) $value))->map(fn ($t) => trim((string) $t))->filter()->map(fn ($t) => str_starts_with($t, '#') ? $t : "#{$t}")->values()->all();
                } else {
                    $value = trim((string) $value);
                }
                $count = Copy::count($f ?? CopyField::Caption, $value);
                $clean[$field] = $value;
                $checks[$field] = ['count' => $count, 'limit' => $limit, 'ok' => $count <= $limit];
            }
            $clean['checks'] = $checks;
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $versions
     * @return list<string>
     */
    private function over(array $versions): array
    {
        $over = [];
        foreach ($versions as $i => $version) {
            foreach ($version['checks'] as $field => $check) {
                if (! $check['ok']) {
                    $over[] = 'version '.($i + 1)." {$field} is {$check['count']} (limit {$check['limit']})";
                }
            }
        }

        return $over;
    }

    private function spend(CreativeRequest $request, string $modelName, float $spent, ?Placement $placement, string $language): void
    {
        if ($spent > 0) {
            $provider = (string) (LlmModel::where('model', $modelName)->value('provider') ?: 'text');
            $this->budgets->record($provider, 'copy', $spent, request: $request, note: ($placement?->label() ?? 'Ad words')." ({$language})");
        }
    }
}
