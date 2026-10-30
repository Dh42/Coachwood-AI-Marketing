<?php

namespace App\Requests;

use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\ImageModel;
use App\Models\Placement;
use App\Models\PromptRevision;
use App\Prompts\Prompts;
use App\Prompts\PromptType;
use App\Prompts\ResolvedPrompt;
use App\Settings\Settings;
use Illuminate\Support\Collection;

/**
 * What the planner is told: who it works for (the brand kit), how to read
 * a brief, when to ask and when to decide, and how to write the image
 * prompt. The text is the brand's planner prompt (editable, with
 * revisions); the app fills {brand}, {brand_kit} and {asking}.
 */
class PlannerPrompt
{
    public function __construct(private Prompts $prompts, private Settings $settings) {}

    /**
     * @param  PromptRevision|null  $using  An unsaved draft to try instead of the brand's current prompt.
     */
    public function system(Brand $brand, CreativeRequest $request, bool $mayAsk, ?PromptRevision $using = null): ResolvedPrompt
    {
        return $this->prompts->resolve(PromptType::Planner, $brand, $request->user, ['asking' => $this->asking($mayAsk)], $request->languages[0] ?? 'en', $using);
    }

    /**
     * What {asking} becomes: the rule for asking questions, or that no more can be asked.
     */
    public function asking(bool $mayAsk): string
    {
        $max = (int) $this->settings->get('requests.max_questions');

        return $mayAsk
            ? "Ask only when the answer changes what gets made and the brief doesn't say: the product (when more than one fits), the size or flavour (when it matters to the picture), the offer details (percent off, dates, a code), the audience, or the call to action. Ask at most {$max} questions in one round with ask_questions, each with the choices as options where there are natural ones, then stop and wait. If nothing needs asking, go straight to the plan."
            : 'No more questions can be asked on this request. Plan with sensible assumptions and list them in the summary.';
    }

    /**
     * The request itself, as the planner's first message.
     *
     * @param  Collection<int, Placement>  $placements
     * @param  Collection<int, ImageModel>  $models
     */
    public function request(CreativeRequest $request, Collection $placements, Collection $models, int $perRatio): string
    {
        $lines = ["Brief:\n{$request->brief}", ''];

        $lines[] = 'Output: '.CreativeRequest::OUTPUTS[$request->output].($request->output !== 'image' ? ' ('.($request->ad_style === 'composed' ? 'composed: words and logo set on top by the app' : 'generated: the image model draws the words').')' : '');
        $lines[] = 'Copy wanted: '.CreativeRequest::COPY[$request->copy];
        $lines[] = 'Languages: '.implode(', ', $request->languageNames());
        $lines[] = "Images per shape: {$perRatio}";

        $lines[] = "\nPlacements, by the shape they're generated at:";
        foreach ($placements->groupBy('ratio')->sortKeys() as $ratio => $group) {
            $lines[] = "- {$ratio}: ".$group->map(fn (Placement $p) => "{$p->label()} ({$p->sizeLabel()})")->implode(', ');
            $notes = $group->map(fn (Placement $p) => $p->platform->prompt_note)->filter()->unique();
            if ($notes->isNotEmpty()) {
                $lines[] = '  Platform notes: '.$notes->implode(' ');
            }
        }

        if ($request->image_model) {
            $lines[] = "\nImage model: the person chose {$request->image_model}; use it.";
        } else {
            $lines[] = "\nImage models available (key: what it takes):";
            foreach ($models as $model) {
                $lines[] = "- {$model->key()}: {$model->label}; {$model->summary()}";
            }
        }

        if ($request->references->isNotEmpty()) {
            $lines[] = "\nReference images uploaded:";
            foreach ($request->references as $ref) {
                $lines[] = "- {$ref->original_name}: ".($ref->kind === 'product' ? 'a pack shot of the product to use' : 'the look and mood wanted');
            }
        }

        if ($request->variant) {
            $lines[] = "\nProduct already chosen: {$request->variant->product->title}, {$request->variant->label()} (variant id {$request->variant->id}).";
        }

        if ($request->questions && $request->answers) {
            $lines[] = "\nQuestions asked so far, and the answers:";
            foreach ($request->questions as $q) {
                $answer = $request->answers[$q['id']] ?? null;
                $label = collect($q['options'] ?? [])->firstWhere('value', $answer)['label'] ?? $answer;
                $lines[] = "- Q: {$q['question']}\n  A: ".($label ?? '(no answer)');
            }
        }

        return implode("\n", $lines);
    }
}
