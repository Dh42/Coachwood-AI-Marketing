<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved version of a prompt: the text, the review criteria and hard
 * fails where the prompt is a review prompt, who saved it and how.
 */
#[Fillable(['prompt_id', 'number', 'body', 'settings', 'note', 'method', 'request', 'restored_from', 'user_id', 'created_at'])]
class PromptRevision extends Model
{
    public const UPDATED_AT = null;

    public const STARTER = 'starter';

    public const HAND = 'hand';

    public const AI = 'ai';

    public const ROLLBACK = 'rollback';

    protected function casts(): array
    {
        return ['settings' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Prompt, $this>
     */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The criteria rows of a review prompt: key, label, weight, what to look for.
     *
     * @return list<array{key: string, label: string, weight: int, what: string}>
     */
    public function criteria(): array
    {
        return array_values(array_map(fn ($c) => ['key' => (string) $c['key'], 'label' => (string) $c['label'], 'weight' => (int) $c['weight'], 'what' => (string) ($c['what'] ?? '')], $this->settings['criteria'] ?? []));
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function hardFails(): array
    {
        return array_values(array_map(fn ($f) => ['key' => (string) $f['key'], 'label' => (string) $f['label']], $this->settings['hard_fails'] ?? []));
    }

    /**
     * The whole revision as text, for diffs: the body, then the criteria and hard fails when there are any.
     */
    public function asText(): string
    {
        $parts = [rtrim($this->body)];
        if ($this->criteria() !== []) {
            $parts[] = "Criteria:\n".implode("\n", array_map(fn ($c) => "- {$c['label']} (weight {$c['weight']}): {$c['what']}", $this->criteria()));
        }
        if ($this->hardFails() !== []) {
            $parts[] = "Hard fails:\n".implode("\n", array_map(fn ($f) => "- {$f['label']}", $this->hardFails()));
        }

        return implode("\n\n", $parts);
    }

    /**
     * "Edited by hand", "Drafted by AI from: …", "Rolled back to v2".
     */
    public function methodLabel(): string
    {
        return match ($this->method) {
            self::STARTER => 'Starting text',
            self::AI => 'Drafted by AI'.($this->request ? ' from: '.mb_substr($this->request, 0, 120) : ''),
            self::ROLLBACK => 'Rolled back to v'.($this->restored_from ? (PromptRevision::find($this->restored_from)?->number ?? '?') : '?'),
            default => 'Edited by hand',
        };
    }
}
