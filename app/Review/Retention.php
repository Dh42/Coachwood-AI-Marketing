<?php

namespace App\Review;

use App\Images\ImageFiles;
use App\Models\Brand;
use App\Models\Generation;
use App\Models\Output;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Deletes the files of rejected outputs once they've been kept long enough
 * for someone to disagree with the reviewer. The row stays, with its score
 * and reason, so the record of what was made and why it was rejected is
 * kept; only the disk space goes.
 */
class Retention
{
    public function __construct(private Settings $settings, private ImageFiles $files) {}

    /**
     * Removes what is due on every brand and returns how many outputs' files went.
     */
    public function prune(): int
    {
        $removed = 0;

        foreach (Brand::query()->get() as $brand) {
            $count = 0;

            // An original is kept while a version made from it is still there, so a chain goes in passes.
            do {
                $pass = 0;
                $this->due($brand)->chunkById(100, function ($outputs) use (&$pass) {
                    foreach ($outputs as $output) {
                        $this->remove($output);
                        $pass++;
                    }
                });
                $count += $pass;
            } while ($pass > 0);

            if ($count > 0) {
                activity('requests')->event('rejects_removed')->withProperties(['brand_id' => $brand->id, 'outputs' => $count, 'days' => $this->days($brand)])
                    ->log("Removed the files of {$count} rejected ".str('output')->plural($count)." on {$brand->name}, kept {$this->days($brand)} ".str('day')->plural($this->days($brand)).' after review');
            }

            $removed += $count;
        }

        return $removed;
    }

    /**
     * Rejected outputs on a brand whose files are still there and whose
     * time is up. An image that still has a living changed version is kept,
     * since further changes are made from the original.
     *
     * @return Builder<Output>
     */
    public function due(Brand $brand): Builder
    {
        $cutoff = $this->cutoff($brand);

        return Output::query()
            ->where('status', Shortlist::REJECTED)
            ->whereNull('removed_at')
            ->where('updated_at', '<=', $cutoff)
            ->whereHas('request', fn (Builder $q) => $q->where('brand_id', $brand->id))
            ->whereHas('generation', fn (Builder $q) => $q->whereIn('status', [Generation::DONE, Generation::FAILED]))
            ->whereDoesntHave('versions', fn (Builder $q) => $q->whereNull('removed_at'));
    }

    public function days(Brand $brand): int
    {
        return (int) $this->settings->get('retention.rejects_days', $brand->id);
    }

    public function cutoff(Brand $brand): Carbon
    {
        return now()->subDays($this->days($brand));
    }

    private function remove(Output $output): void
    {
        foreach (array_filter([$output->path, $output->thumb_path]) as $path) {
            $this->files->disk()->delete($path);
        }

        $output->forceFill(['removed_at' => now()])->saveQuietly();
    }
}
