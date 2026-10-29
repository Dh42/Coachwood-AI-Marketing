<?php

use App\Models\LlmModel;

it('offers Claude Opus 5.5 at its own prices, first in the list', function () {
    $opus = LlmModel::where('model', 'claude-opus-5-5')->sole();

    // A million of each: input $4, output $20, cache write $5, cache read $0.20.
    expect($opus->cost(1_000_000, 1_000_000, 1_000_000, 1_000_000))->toEqualWithDelta(29.2, 0.0001)
        ->and(LlmModel::orderBy('position')->value('model'))->toBe('claude-opus-5-5')
        ->and($opus->refusal_fallback)->toBeFalse();
});

it('offers Claude Sonnet 5.5 at its own prices, above Sonnet 5', function () {
    $sonnet = LlmModel::where('model', 'claude-sonnet-5-5')->sole();

    // A million of each: input $2, output $10, cache write $2.50, cache read $0.10.
    expect($sonnet->cost(1_000_000, 1_000_000, 1_000_000, 1_000_000))->toEqualWithDelta(14.6, 0.0001)
        ->and($sonnet->position)->toBeLessThan(LlmModel::where('model', 'claude-sonnet-5')->value('position'));
});
