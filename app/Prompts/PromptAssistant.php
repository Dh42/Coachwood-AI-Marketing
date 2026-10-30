<?php

namespace App\Prompts;

use App\Budgets\Budgets;
use App\Llm\LlmManager;
use App\Llm\LlmRequest;
use App\Llm\LlmResponse;
use App\Llm\ToolSpec;
use App\Models\LlmModel;
use App\Models\Prompt;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Describe the problem": drafts a changed prompt from what the team
 * says is going wrong, for them to accept, adjust or discard. Nothing is
 * saved here.
 */
class PromptAssistant
{
    public function __construct(private LlmManager $llm, private Prompts $prompts, private Budgets $budgets, private Settings $settings) {}

    /**
     * @return array{body: string, settings: array<string, mixed>|null, explanation: string}
     *
     * @throws RuntimeException
     */
    public function draft(Prompt $prompt, string $problem, User $by): array
    {
        $brand = $prompt->brand;
        $type = $prompt->type;
        $current = $prompt->current;
        $modelName = (string) $this->settings->get('llm.default_model', $brand->id);
        $spent = 0.0;

        $messages = [['role' => 'user', 'content' => $this->message($prompt, $problem)]];
        $call = null;

        try {
            for ($turn = 0; $turn < 2; $turn++) {
                $response = $this->llm->complete(new LlmRequest($modelName, $this->system($type), $messages, [$this->tool($type)], maxTokens: 12000, effort: (string) $this->settings->get('llm.effort', $brand->id), brandId: $brand->id));
                $spent += $response->cost;
                if ($response->stopReason === LlmResponse::REFUSAL) {
                    throw new RuntimeException('The model declined to change this prompt.');
                }
                $messages[] = $response->assistantMessage;
                if ($call = collect($response->toolCalls)->firstWhere('name', 'propose_prompt')) {
                    break;
                }
                $messages[] = ['role' => 'user', 'content' => 'Call propose_prompt with the changed prompt now.'];
            }
        } finally {
            if ($spent > 0) {
                $provider = (string) (LlmModel::where('model', $modelName)->value('provider') ?: 'text');
                $this->budgets->record($provider, 'prompt', $spent, $brand, $by, note: 'Prompt change drafted: '.$type->label());
            }
        }

        if (! $call) {
            throw new RuntimeException('The model sent no changed prompt. Try describing the problem differently.');
        }

        $input = $call['input'];
        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '') {
            throw new RuntimeException('The model sent an empty prompt.');
        }

        $settings = null;
        if ($type->hasCriteria()) {
            // A row the model sent back under its old name keeps its key: reviews are stored by it.
            $keyed = fn (array $rows, array $existing) => array_map(function ($row) use ($existing) {
                $row = is_array($row) ? $row : ['label' => $row];
                $row['key'] = collect($existing)->first(fn ($e) => Str::lower(trim($e['label'])) === Str::lower(trim((string) ($row['label'] ?? ''))))['key'] ?? '';

                return $row;
            }, $rows);
            $settings = $this->prompts->cleanSettings([
                'criteria' => isset($input['criteria']) && is_array($input['criteria']) ? $keyed($input['criteria'], $current->criteria()) : $current->criteria(),
                'hard_fails' => isset($input['hard_fails']) && is_array($input['hard_fails']) ? $keyed($input['hard_fails'], $current->hardFails()) : $current->hardFails(),
            ]);
        }

        return ['body' => $body, 'settings' => $settings, 'explanation' => mb_substr(trim((string) ($input['explanation'] ?? '')), 0, 600)];
    }

    private function system(PromptType $type): string
    {
        $placeholders = collect($type->placeholders())->map(fn ($what, $key) => "{{$key}}: {$what}")->implode("\n");
        $kept = $placeholders !== '' ? "\n\nThe prompt has placeholders the app fills at run time. Keep every one that's there, exactly as written:\n{$placeholders}" : '';
        $criteria = $type->hasCriteria()
            ? "\n\nThis is a review prompt. Besides the text, it has scored criteria (a name, a weight from 1 to 100, and what to look for) and hard fails (one is enough to reject an output). Change them when the problem is about what's scored or how much it counts: add a criterion, change a weight, sharpen what to look for, or add a hard fail. Send the complete lists back, not just the changes."
            : '';

        return <<<PROMPT
You tune the prompts of a marketing team's creative app. One prompt is "{$type->label()}": {$type->description()}

A person on the team describes a problem they see in the results. Change the prompt so the problem stops, and change nothing else: keep the structure, the tone and every rule that isn't part of the problem. Make the smallest edit that fixes it, in the same plain style. Don't add headings, markdown or commentary to the prompt itself.{$kept}{$criteria}

Call propose_prompt once with the whole changed prompt and a two-sentence explanation of what you changed and why.
PROMPT;
    }

    private function message(Prompt $prompt, string $problem): string
    {
        $current = $prompt->current;
        $lines = ["The problem: {$problem}", '', "The prompt as it is now:\n---\n{$current->body}\n---"];
        if ($prompt->type->hasCriteria()) {
            $lines[] = "\nCriteria (name, weight, what to look for):\n".implode("\n", array_map(fn ($c) => "- {$c['label']} ({$c['weight']}): {$c['what']}", $current->criteria()));
            $lines[] = "\nHard fails:\n".(implode("\n", array_map(fn ($f) => "- {$f['label']}", $current->hardFails())) ?: '- (none)');
        }

        return implode("\n", $lines);
    }

    private function tool(PromptType $type): ToolSpec
    {
        $properties = [
            'body' => ['type' => 'string', 'description' => 'The whole changed prompt, with its placeholders kept.'],
            'explanation' => ['type' => 'string', 'description' => 'Two sentences: what changed and why.'],
        ];
        $required = ['body', 'explanation'];
        if ($type->hasCriteria()) {
            $properties['criteria'] = ['type' => 'array', 'description' => 'The complete list of criteria.', 'items' => ['type' => 'object', 'properties' => [
                'label' => ['type' => 'string'], 'weight' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'what' => ['type' => 'string'],
            ], 'required' => ['label', 'weight', 'what']]];
            $properties['hard_fails'] = ['type' => 'array', 'description' => 'The complete list of hard fails.', 'items' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label']]];
            $required = [...$required, 'criteria', 'hard_fails'];
        }

        return new ToolSpec('propose_prompt', 'Proposes the changed prompt.', ['type' => 'object', 'properties' => $properties, 'required' => $required]);
    }
}
