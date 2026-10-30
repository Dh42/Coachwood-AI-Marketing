<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Models\User;
use App\Prompts\PromptTrials;
use App\Prompts\PromptType;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one "Try it" from the Prompts page: an unsaved prompt on a sample,
 * with the result written to the cache for the page to pick up.
 */
class TryPrompt implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  array<string, mixed>|null  $settings
     * @param  array{brief: string, scene: string, change: string}  $samples
     */
    public function __construct(
        public string $token,
        public Brand $brand,
        public PromptType $type,
        public User $by,
        public string $body,
        public ?array $settings,
        public array $samples,
    ) {}

    public function handle(PromptTrials $trials): void
    {
        $trials->run($this->token, $this->brand, $this->type, $this->by, $this->body, $this->settings, $this->samples);
    }

    public function failed(?Throwable $e): void
    {
        app(PromptTrials::class)->fail($this->token, $this->brand, $this->type, 'The try didn\'t finish: it ran out of time or the worker stopped. Try again.');
    }
}
