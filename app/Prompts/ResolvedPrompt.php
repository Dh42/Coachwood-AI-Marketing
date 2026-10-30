<?php

namespace App\Prompts;

use App\Models\Prompt;
use App\Models\PromptRevision;

/**
 * A prompt ready to send: the brand's text with the placeholders filled
 * and the person's additions appended, and the revisions it was made
 * from, to record on what it produced.
 */
final readonly class ResolvedPrompt
{
    public function __construct(
        public string $text,
        public Prompt $prompt,
        public PromptRevision $revision,
        public ?PromptRevision $additionRevision = null,
    ) {}

    /**
     * The columns to record on a request, generation, copy or review (null
     * ids when the prompt was an unsaved draft being tried).
     *
     * @return array{prompt_revision_id: int|null, addition_revision_id: int|null}
     */
    public function ids(): array
    {
        return ['prompt_revision_id' => $this->revision->id, 'addition_revision_id' => $this->additionRevision?->id];
    }

    /**
     * The review criteria as the reviewer scores them: key => [label, weight, what].
     *
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public function criteria(): array
    {
        $out = [];
        foreach ($this->revision->criteria() as $c) {
            $out[$c['key']] = [$c['label'], $c['weight'], $c['what']];
        }

        return $out;
    }

    /**
     * @return array<string, string> key => label
     */
    public function hardFails(): array
    {
        return array_column($this->revision->hardFails(), 'label', 'key');
    }
}
