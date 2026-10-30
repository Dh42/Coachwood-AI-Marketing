<?php

namespace App\Prompts;

use App\BrandKit\BrandKits;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Prompt;
use App\Models\PromptRevision;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The brand's prompts and everyone's additions to them: made from the
 * starters when first needed, saved as revisions, rolled back as new
 * revisions, and resolved into the text the models get.
 */
class Prompts
{
    public const MAX_CRITERIA = 10;

    public const MAX_HARD_FAILS = 10;

    public function __construct(private BrandKits $kits) {}

    /**
     * The brand's prompt of a type, made from the starter if it doesn't exist yet.
     */
    public function for(Brand $brand, PromptType $type): Prompt
    {
        $prompt = Prompt::with('current')->where('brand_id', $brand->id)->where('type', $type)->whereNull('user_id')->first();
        if ($prompt && $prompt->current) {
            return $prompt;
        }

        return DB::transaction(function () use ($brand, $type, $prompt) {
            $prompt ??= Prompt::create(['brand_id' => $brand->id, 'type' => $type]);
            $this->revise($prompt, Starters::body($type), Starters::settings($type), null, PromptRevision::STARTER, null, null, null);

            return $prompt->load('current');
        });
    }

    /**
     * Every type's prompt for the brand, in order.
     *
     * @return Collection<int, Prompt>
     */
    public function all(Brand $brand): Collection
    {
        return new Collection(array_map(fn (PromptType $t) => $this->for($brand, $t), PromptType::cases()));
    }

    /**
     * One person's additions to a prompt type, if they've written any.
     */
    public function addition(Brand $brand, PromptType $type, User $user): ?Prompt
    {
        return Prompt::with('current')->where('brand_id', $brand->id)->where('type', $type)->where('user_id', $user->id)->first();
    }

    /**
     * Everyone's additions to a prompt type (admins can read them).
     *
     * @return Collection<int, Prompt>
     */
    public function additions(Brand $brand, PromptType $type): Collection
    {
        return Prompt::with(['current', 'user'])->where('brand_id', $brand->id)->where('type', $type)->whereNotNull('user_id')->get()->filter(fn (Prompt $p) => trim($p->body()) !== '')->values();
    }

    /**
     * The text to send: the brand's prompt with the placeholders filled and
     * the person's additions after it.
     *
     * @param  array<string, string>  $fill  Placeholder => value, on top of {brand} and {brand_kit}.
     * @param  PromptRevision|null  $using  A revision to use instead of the current one: an unsaved draft, for "Try it".
     */
    public function resolve(PromptType $type, Brand $brand, ?User $user = null, array $fill = [], string $lang = 'en', ?PromptRevision $using = null): ResolvedPrompt
    {
        $prompt = $this->for($brand, $type);
        $revision = $using ?? $prompt->current;
        $body = $revision->body;

        $fill += ['brand' => $brand->name];
        if (str_contains($body, '{brand_kit}')) {
            $fill += ['brand_kit' => $this->kits->summary($brand, $lang)];
        }
        if ($type->hasCriteria()) {
            $fill += [
                'criteria' => implode("\n", array_map(fn ($c) => "- {$c['key']} ({$c['label']}, weight {$c['weight']}): {$c['what']}", $revision->criteria())),
                'hard_fails' => implode("\n", array_map(fn ($f) => "- {$f['key']}: {$f['label']}", $revision->hardFails())) ?: '- (none)',
            ];
        }
        $text = self::fill($body, $fill);

        $addition = $user ? $this->addition($brand, $type, $user) : null;
        $additionRevision = $addition && trim($addition->body()) !== '' ? $addition->current : null;
        if ($additionRevision) {
            $text .= "\n\nAdditions from {$user->name}:\n".trim($additionRevision->body);
        }

        return new ResolvedPrompt($text, $prompt, $revision, $additionRevision);
    }

    /**
     * @param  array<string, string>  $fill
     */
    public static function fill(string $body, array $fill): string
    {
        $replace = [];
        foreach ($fill as $key => $value) {
            $replace['{'.$key.'}'] = $value;
        }

        return strtr($body, $replace);
    }

    /**
     * Saves a new revision of the brand's prompt. Nothing saved when nothing changed.
     *
     * @param  array{criteria?: list<array<string, mixed>>, hard_fails?: list<array<string, mixed>>}|null  $settings
     *
     * @throws ValidationException
     */
    public function save(Prompt $prompt, string $body, ?array $settings, ?string $note, string $method, ?string $request, User $by): PromptRevision
    {
        $type = $prompt->type;
        $body = self::clean($body);
        $settings = $type->hasCriteria() ? $this->cleanSettings($settings ?? []) : null;
        $this->validate($type, $body, $settings, $note);

        $current = $prompt->current;
        if ($current && ! PromptDiff::changed($current->asText(), (new PromptRevision(['body' => $body, 'settings' => $settings]))->asText())) {
            throw ValidationException::withMessages(['body' => 'Nothing changed.']);
        }

        $revision = $this->revise($prompt, $body, $settings, $note, $method, $request, null, $by);
        activity('prompts')->causedBy($by)->performedOn($prompt)->event('prompt_saved')->withProperties(['type' => $type->value, 'revision' => $revision->number, 'method' => $method])
            ->log("Saved {$prompt->brand->name}'s ".Str::lower($type->label())." prompt (v{$revision->number}, ".($method === PromptRevision::AI ? 'drafted by AI' : 'by hand').')');

        return $revision;
    }

    /**
     * Makes an old revision the current one, as a new revision.
     */
    public function rollBack(Prompt $prompt, PromptRevision $to, User $by): PromptRevision
    {
        if ($to->prompt_id !== $prompt->id) {
            throw ValidationException::withMessages(['body' => 'That revision belongs to another prompt.']);
        }
        if ($to->id === $prompt->current_revision_id) {
            throw ValidationException::withMessages(['body' => "v{$to->number} is already the current one."]);
        }

        $revision = $this->revise($prompt, $to->body, $to->settings, $to->note, PromptRevision::ROLLBACK, null, $to->id, $by);
        activity('prompts')->causedBy($by)->performedOn($prompt)->event('prompt_rolled_back')->withProperties(['type' => $prompt->type->value, 'revision' => $revision->number, 'to' => $to->number])
            ->log("Rolled {$prompt->brand->name}'s ".Str::lower($prompt->type->label())." prompt back to v{$to->number} (as v{$revision->number})");

        return $revision;
    }

    /**
     * Saves one person's additions to a prompt type; empty text means none.
     */
    public function saveAddition(Brand $brand, PromptType $type, User $user, string $body): ?PromptRevision
    {
        $body = self::clean($body);
        Validator::make(['body' => $body], ['body' => ['nullable', 'string', 'max:5000']], [], ['body' => 'your additions'])->validate();

        $prompt = Prompt::firstOrCreate(['brand_id' => $brand->id, 'type' => $type, 'user_id' => $user->id]);
        if (($prompt->current?->body ?? '') === $body) {
            return $prompt->current;
        }

        $revision = $this->revise($prompt, $body, null, null, PromptRevision::HAND, null, null, $user);
        activity('prompts')->causedBy($user)->performedOn($prompt)->event('addition_saved')->withProperties(['type' => $type->value])
            ->log(($body === '' ? 'Removed their additions to ' : 'Changed their additions to ')."{$brand->name}'s ".Str::lower($type->label()).' prompt');

        return $revision;
    }

    /**
     * Every revision, newest first.
     *
     * @return Collection<int, PromptRevision>
     */
    public function history(Prompt $prompt): Collection
    {
        return $prompt->revisions()->with('user')->orderByDesc('number')->get();
    }

    /**
     * The prompt versions a request's work used, as lines for its page:
     * "Planner v2", "Images v1 + Ada's additions v1", "Review: images v1".
     *
     * @return list<string>
     */
    public function usedBy(CreativeRequest $request): array
    {
        $pairs = [[$request->prompt_revision_id, $request->addition_revision_id]];
        foreach ($request->generations as $g) {
            $pairs[] = [$g->prompt_revision_id, $g->addition_revision_id];
            if ($g->change) {
                $pairs[] = [$g->change['prompt_revision_id'] ?? null, $g->change['addition_revision_id'] ?? null];
            }
        }
        foreach ($request->copies as $c) {
            $pairs[] = [$c->prompt_revision_id, $c->addition_revision_id];
        }
        foreach (Review::whereIn('output_id', $request->outputs()->select('id'))->get() as $r) {
            $pairs[] = [$r->prompt_revision_id, $r->addition_revision_id];
        }

        $ids = collect($pairs)->flatten()->filter()->unique()->values();
        $revisions = PromptRevision::with(['prompt.user'])->whereIn('id', $ids)->get()->keyBy('id');
        $lines = [];
        foreach (collect($pairs)->filter(fn ($p) => $p[0])->unique(fn ($p) => "{$p[0]}-{$p[1]}") as [$main, $addition]) {
            $m = $revisions[$main] ?? null;
            if (! $m) {
                continue;
            }
            $line = "{$m->prompt->type->label()} v{$m->number}";
            if ($addition && ($a = $revisions[$addition] ?? null)) {
                $line .= " + {$a->prompt->user?->name}'s additions v{$a->number}";
            }
            $lines[] = $line;
        }

        return array_values(array_unique($lines));
    }

    /**
     * Placeholders the prompt type fills that the text no longer has.
     *
     * @return list<string>
     */
    public static function missingPlaceholders(PromptType $type, string $body): array
    {
        return array_values(array_filter(array_keys($type->placeholders()), fn ($p) => ! str_contains($body, '{'.$p.'}')));
    }

    /**
     * Tidies criteria and hard fails from a form or the assistant: trimmed, keys kept or made from the label, numbered weights.
     *
     * @param  array{criteria?: list<array<string, mixed>>, hard_fails?: list<array<string, mixed>>}  $settings
     * @return array{criteria: list<array{key: string, label: string, weight: int, what: string}>, hard_fails: list<array{key: string, label: string}>}
     */
    public function cleanSettings(array $settings): array
    {
        $criteria = [];
        $keys = [];
        foreach (array_values((array) ($settings['criteria'] ?? [])) as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '' && trim((string) ($row['what'] ?? '')) === '') {
                continue;
            }
            $criteria[] = ['key' => $this->key($label, $keys, (string) ($row['key'] ?? '')), 'label' => $label, 'weight' => (int) ($row['weight'] ?? 0), 'what' => trim((string) ($row['what'] ?? ''))];
        }
        $fails = [];
        $keys = [];
        foreach (array_values((array) ($settings['hard_fails'] ?? [])) as $row) {
            $label = trim((string) (is_array($row) ? ($row['label'] ?? '') : $row));
            if ($label === '') {
                continue;
            }
            $fails[] = ['key' => $this->key($label, $keys, (string) (is_array($row) ? ($row['key'] ?? '') : '')), 'label' => $label];
        }

        return ['criteria' => $criteria, 'hard_fails' => $fails];
    }

    /**
     * A row keeps the key it had (reviews are stored by it); a new row gets one from its label.
     *
     * @param  list<string>  $taken
     */
    private function key(string $label, array &$taken, string $given = ''): string
    {
        $base = Str::slug(Str::limit($given ?: $label, 40, ''), '_') ?: 'item';
        $key = $base;
        for ($n = 2; in_array($key, $taken, true); $n++) {
            $key = "{$base}_{$n}";
        }
        $taken[] = $key;

        return $key;
    }

    /**
     * An unsaved revision from a form or a draft, cleaned the way a save would clean it, for trying or diffing.
     *
     * @param  array{criteria?: list<array<string, mixed>>, hard_fails?: list<array<string, mixed>>}|null  $settings
     */
    public function draft(PromptType $type, string $body, ?array $settings): PromptRevision
    {
        return new PromptRevision(['body' => self::clean($body), 'settings' => $type->hasCriteria() ? $this->cleanSettings($settings ?? []) : null]);
    }

    /**
     * @param  array{criteria: list<array{key: string, label: string, weight: int, what: string}>, hard_fails: list<array{key: string, label: string}>}|null  $settings
     *
     * @throws ValidationException
     */
    public function validate(PromptType $type, string $body, ?array $settings, ?string $note = null): void
    {
        $rules = ['body' => ['required', 'string', 'max:30000'], 'note' => ['nullable', 'string', 'max:200']];
        $names = ['body' => 'the prompt', 'note' => 'the note'];
        if ($type->hasCriteria()) {
            $rules['criteria'] = ['required', 'array', 'min:1', 'max:'.self::MAX_CRITERIA];
            $rules['criteria.*.label'] = ['required', 'string', 'max:60'];
            $rules['criteria.*.weight'] = ['required', 'integer', 'min:1', 'max:100'];
            $rules['criteria.*.what'] = ['nullable', 'string', 'max:400'];
            $rules['hard_fails'] = ['array', 'max:'.self::MAX_HARD_FAILS];
            $rules['hard_fails.*.label'] = ['required', 'string', 'max:160'];
            $names += ['criteria' => 'the criteria', 'criteria.*.label' => 'the criterion\'s name', 'criteria.*.weight' => 'the weight', 'criteria.*.what' => 'what to look for', 'hard_fails.*.label' => 'the hard fail'];
        }

        Validator::make(['body' => $body, 'note' => $note, ...($settings ?? [])], $rules, [
            'body.required' => 'The prompt can\'t be empty.',
            'criteria.required' => 'A review prompt needs at least one criterion.',
            'criteria.min' => 'A review prompt needs at least one criterion.',
            'criteria.max' => 'At most '.self::MAX_CRITERIA.' criteria.',
            'criteria.*.weight.min' => 'Each weight is a whole number from 1 to 100.',
            'criteria.*.weight.max' => 'Each weight is a whole number from 1 to 100.',
            'criteria.*.weight.integer' => 'Each weight is a whole number from 1 to 100.',
        ], $names)->validate();
    }

    /**
     * @param  array<string, mixed>|null  $settings
     */
    private function revise(Prompt $prompt, string $body, ?array $settings, ?string $note, string $method, ?string $request, ?int $restoredFrom, ?User $by): PromptRevision
    {
        return DB::transaction(function () use ($prompt, $body, $settings, $note, $method, $request, $restoredFrom, $by) {
            $number = (int) $prompt->revisions()->max('number') + 1;
            $revision = $prompt->revisions()->create([
                'number' => $number, 'body' => $body, 'settings' => $settings, 'note' => trim((string) $note) ?: null, 'method' => $method,
                'request' => trim((string) $request) ?: null, 'restored_from' => $restoredFrom, 'user_id' => $by?->id, 'created_at' => now(),
            ]);
            $prompt->forceFill(['current_revision_id' => $revision->id])->save();
            $prompt->setRelation('current', $revision);

            return $revision;
        });
    }

    private static function clean(string $body): string
    {
        return trim(str_replace("\r\n", "\n", $body));
    }
}
