<?php

namespace App\Copy;

use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Placement;
use App\Models\PromptRevision;
use App\Models\User;
use App\Platforms\CopyField;
use App\Prompts\Prompts;
use App\Prompts\PromptType;
use App\Prompts\ResolvedPrompt;

/**
 * What the copywriter is told: the brand's voice, the plan, the placement
 * and its limits, the language. The system text is the brand's copy
 * prompt (editable, with revisions); the app fills {brand}, {brand_kit}
 * and {language}.
 */
class CopyPrompt
{
    public function __construct(private Prompts $prompts) {}

    /**
     * @param  PromptRevision|null  $using  An unsaved draft to try instead of the brand's current prompt.
     */
    public function system(Brand $brand, string $language, ?User $user = null, ?PromptRevision $using = null): ResolvedPrompt
    {
        $lang = CreativeRequest::LANGUAGES[$language] ?? $language;

        return $this->prompts->resolve(PromptType::Copy, $brand, $user, ['language' => $lang], $language, $using);
    }

    /**
     * @param  array<string, int>  $limits  CopyField value => limit.
     */
    public function placement(CreativeRequest $request, Placement $placement, array $limits, int $versions, string $language): string
    {
        $lines = [$this->brief($request)];
        $lines[] = "\nPlacement: {$placement->label()}.".($placement->platform->prompt_note ? " {$placement->platform->prompt_note}" : '').($placement->notes ? " {$placement->notes}" : '');
        $lines[] = "\nWrite {$versions} versions. Each version has these pieces, within these limits:";
        foreach ($limits as $field => $limit) {
            $f = CopyField::from($field);
            $lines[] = "- {$f->label()} ({$field}): up to {$limit} {$f->unit()}".($f === CopyField::Hashtags ? ', each starting with #' : '');
        }

        return implode("\n", $lines);
    }

    public function ad(CreativeRequest $request, string $language): string
    {
        $lang = CreativeRequest::LANGUAGES[$language] ?? $language;

        return $this->brief($request)."\n\nWrite the words that go on the finished ads, in {$lang}: a headline of at most 40 characters (the offer, as it would be printed large on the picture), a sub-line of at most 80 characters (the details: dates, code, conditions), and a call to action of at most 20 characters for the button. One version. Call submit_copy with it.";
    }

    private function brief(CreativeRequest $request): string
    {
        $plan = $request->plan ?? [];
        $lines = ["The brief: {$request->brief}"];
        if ($plan['summary'] ?? '') {
            $lines[] = "The plan: {$plan['summary']}";
        }
        if ($h = data_get($plan, 'offer.headline')) {
            $lines[] = "The offer: {$h}".(($d = data_get($plan, 'offer.details')) ? " ({$d})" : '');
        }
        if ($plan['audience'] ?? '') {
            $lines[] = "Audience: {$plan['audience']}";
        }
        if ($plan['cta'] ?? '') {
            $lines[] = "Call to action: {$plan['cta']}";
        }
        if ($request->variant) {
            $lines[] = "Product: {$request->variant->product->title}, {$request->variant->label()}".($request->variant->price ? " (\${$request->variant->price})" : '');
        }

        return implode("\n", $lines);
    }
}
