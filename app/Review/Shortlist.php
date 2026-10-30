<?php

namespace App\Review;

use App\Models\CreativeRequest;
use App\Models\Output;
use App\Settings\Settings;
use Illuminate\Support\Collection;

/**
 * Picks what the team sees when a run finishes: per shape for clean
 * images and per placement and language for ads, the best N scoring at
 * least the minimum, with no hard fail and no failed check. Everything
 * else is rejected with its reason, kept for a day in case the reviewer
 * was wrong.
 */
class Shortlist
{
    public const SHORTLISTED = 'shortlisted';

    public const REJECTED = 'rejected';

    public function __construct(private Settings $settings) {}

    public function make(CreativeRequest $request): void
    {
        $min = (int) $this->settings->get('review.min_score', $request->brand_id);
        $deliver = $request->deliver ?: (int) $this->settings->get('review.deliver', $request->brand_id);

        $outputs = Output::with('review')->where('request_id', $request->id)->get();

        $outputs->groupBy(fn (Output $o) => $o->isAd() ? "ad-{$o->placement_id}-{$o->language}" : "image-{$o->ratio}")
            ->each(fn (Collection $group) => $this->pick($group, $min, $deliver));
    }

    /**
     * Judges one output made after the run (a change, a shape run again)
     * by the same rules, without ranking it against the others: it joins
     * the shortlist when it passes, else it's rejected with the reason.
     * Nothing already shortlisted is replaced.
     */
    public function judge(Output $output): void
    {
        $output->load('review');
        $why = $this->why($output, (int) $this->settings->get('review.min_score', $output->request->brand_id));

        $output->update($why === null ? ['status' => self::SHORTLISTED] : ['status' => self::REJECTED, 'reason' => $why]);
    }

    /**
     * Why an output can't be delivered, or null when it passes.
     */
    private function why(Output $output, int $min): ?string
    {
        $review = $output->review;
        $failed = $output->isAd() ? $output->failedChecks() : [];
        $checksRejecting = collect($output->checks ?? [])->contains(fn ($c) => in_array($c['check'], ['size', 'file_size', 'safe_zone'], true) && ! $c['ok']);

        return match (true) {
            $checksRejecting => 'Failed a check: '.implode(' ', $failed),
            ! $review || $review->status !== 'done' => 'Not reviewed: '.($review?->error ?: 'the review didn\'t run.'),
            $review->hard_fails !== [] => $output->reason,
            $review->overall < $min => "Scored {$review->overall}, under the minimum of {$min}.".($output->reason ? " {$output->reason}" : ''),
            default => null,
        };
    }

    /**
     * @param  Collection<int, Output>  $group
     */
    private function pick(Collection $group, int $min, int $deliver): void
    {
        $ranked = $group->map(fn (Output $output) => ['output' => $output, 'why' => $this->why($output, $min), 'score' => $output->review?->overall ?? -1]);

        $eligible = $ranked->where('why', null)->sortByDesc('score')->values();
        $chosen = $eligible->take($deliver)->pluck('output.id')->all();

        foreach ($ranked as $row) {
            $output = $row['output'];
            if (in_array($output->id, $chosen, true)) {
                $output->update(['status' => self::SHORTLISTED]);
            } else {
                $output->update(['status' => self::REJECTED, 'reason' => $row['why'] ?? "Scored {$row['score']}; the best {$deliver} were chosen.".($output->reason ? " {$output->reason}" : '')]);
            }
        }
    }
}
