<?php

namespace Tests\Support;

use App\Models\Output;
use App\Models\Review;
use App\Review\Reviewer;

/**
 * Stands in for the review model: every output scores the same, so tests
 * about other steps don't spend scripted replies on reviews.
 */
class FakeReviewer extends Reviewer
{
    public function __construct(public int $score = 80) {}

    public function review(Output $output): Review
    {
        $review = Review::updateOrCreate(['output_id' => $output->id], ['model' => 'fake', 'scores' => [], 'overall' => $this->score, 'hard_fails' => [], 'reason' => 'Fine.', 'cost' => 0, 'status' => Review::DONE]);
        $output->update(['score' => $this->score, 'reason' => 'Fine.']);

        return $review;
    }
}
