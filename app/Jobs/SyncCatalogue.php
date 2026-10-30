<?php

namespace App\Jobs;

use App\Catalogue\Catalogue;
use App\Catalogue\Cutouts;
use App\Models\Brand;
use App\Models\CatalogueSync;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads a brand's store in the background, then queues a cut-out for each
 * photo that wants one. One at a time per brand.
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

    public function handle(Catalogue $catalogue, Cutouts $cutouts): void
    {
        $sync = $catalogue->sync($this->brand, $this->source, $this->by);

        // New or changed photos get their cut-outs made, when the brand has a service for it.
        if ($sync->status === CatalogueSync::OK) {
            $cutouts->queue($this->brand, $this->by);
        }
    }
}
