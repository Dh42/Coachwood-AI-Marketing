<?php

namespace App\Review;

use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Output;
use App\Models\PromptRevision;
use App\Models\User;
use App\Prompts\Prompts;
use App\Prompts\PromptType;
use App\Prompts\ResolvedPrompt;

/**
 * What the reviewer is told and asked to score. The text, the criteria
 * with their weights and the hard fails are the brand's review prompts
 * (one for clean images, one for finished ads; editable, with
 * revisions). The overall is computed by the app from the scores, never
 * by the model.
 */
class ReviewPrompt
{
    public function __construct(private Prompts $prompts) {}

    public static function type(Output $output): PromptType
    {
        return $output->isAd() ? PromptType::ReviewAd : PromptType::ReviewImage;
    }

    /**
     * The system text for one output, with its criteria and hard fails.
     *
     * @param  PromptRevision|null  $using  An unsaved draft to try instead of the brand's current prompt.
     */
    public function resolve(Brand $brand, Output $output, ?User $user = null, ?PromptRevision $using = null): ResolvedPrompt
    {
        return $this->prompts->resolve(self::type($output), $brand, $user, [], 'en', $using);
    }

    /**
     * The brief, the plan and what the checks already found, as the reviewer's message text.
     */
    public function message(CreativeRequest $request, Output $output): string
    {
        $plan = $request->plan ?? [];
        $lines = ["The brief: {$request->brief}"];
        if ($plan['summary'] ?? '') {
            $lines[] = "The plan: {$plan['summary']}";
        }
        if ($h = data_get($plan, 'offer.headline')) {
            $lines[] = "The offer: {$h}".(($d = data_get($plan, 'offer.details')) ? " ({$d})" : '');
        }
        if ($plan['image_prompt'] ?? '') {
            $lines[] = "The image prompt: {$plan['image_prompt']}";
        }
        if ($request->variant) {
            $lines[] = "The product: {$request->variant->product->title}, {$request->variant->label()}";
        }
        if ($output->isAd()) {
            $lines[] = 'Placement: '.($output->placement?->label() ?? $output->ratio).'.';
            $checks = collect($output->checks ?? [])->map(fn ($c) => ($c['ok'] ? 'ok' : 'FAILED').": {$c['note']}")->implode('; ');
            if ($checks) {
                $lines[] = "Checks already done by the app (size, file size, safe zone, product): {$checks}.";
            }
        } else {
            $lines[] = "Shape: {$output->ratio}.";
        }

        return implode("\n", $lines);
    }
}
