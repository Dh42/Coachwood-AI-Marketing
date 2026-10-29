<?php

namespace App\Scheduler;

use App\Models\ScheduledTaskRun;

/**
 * One task on the scheduler's timer, described for the Scheduled tasks page.
 */
final readonly class ScheduledTask
{
    public function __construct(
        /** The artisan command, such as "alerts:check". */
        public string $command,
        /** What it does, in plain words: the command's own description. */
        public string $description,
        /** How often it runs, in plain words: "Every minute", "Daily at 4:10 AM". */
        public string $every,
        /** The cron expression, for "Advanced". */
        public string $expression,
        /** Whether the scheduler lets it run on while it starts the next task. */
        public bool $background,
        public ?ScheduledTaskRun $lastRun = null,
    ) {}

    public function withLastRun(?ScheduledTaskRun $run): self
    {
        return new self($this->command, $this->description, $this->every, $this->expression, $this->background, $run);
    }
}
