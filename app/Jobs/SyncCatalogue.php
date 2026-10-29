<?php

namespace App\Jobs;

use App\Catalogue\Catalogue;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a brand's store in the background. One at a time per brand.
 */
class SyncCatalogue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1500;

    public int $uniqueFor = 1800;

    public function __construct(public Brand $brand, public string $source = 'schedule', public ?User $by = null)
    {
        $this->onConnection(config('queue.long'));
    }

    public function uniqueId(): string
    {
        return (string) $this->brand->id;
    }

    public function handle(Catalogue $catalogue): void
    {
        $catalogue->sync($this->brand, $this->source, $this->by);
    }
}
