<?php

namespace App\Jobs;

use App\Catalogue\CutoutException;
use App\Catalogue\Cutouts;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Cuts one store photo out in the background, once at a time per photo.
 * A problem worth another go (the service busy, the store's CDN down)
 * is tried again; any other is written on the photo for the Products
 * page to show.
 */
class MakeCutout implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $timeout = 180;

    public int $uniqueFor = 600;

    public function __construct(public ProductImage $image, public ?User $by = null) {}

    public function uniqueId(): string
    {
        return (string) $this->image->id;
    }

    public function handle(Cutouts $cutouts): void
    {
        $image = $this->image->fresh();
        if (! $image || $image->removed_at !== null || $image->hasCutout()) {
            return;
        }

        try {
            $cutouts->make($image, $this->by);
        } catch (CutoutException $e) {
            if ($e->retryable && $this->attempts() < $this->tries) {
                throw $e;
            }
            // Written on the photo already; nothing more to do.
        }
    }
}
