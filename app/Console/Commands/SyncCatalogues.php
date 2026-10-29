<?php

namespace App\Console\Commands;

use App\Catalogue\Catalogue;
use App\Jobs\SyncCatalogue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('catalogue:sync-due')]
#[Description('Read each brand\'s store once a day, at the time in its Store settings')]
class SyncCatalogues extends Command
{
    public function handle(Catalogue $catalogue): int
    {
        $due = $catalogue->due();

        foreach ($due as $brand) {
            SyncCatalogue::dispatch($brand);
            $this->info("Queued {$brand->name}.");
        }

        if ($due->isEmpty()) {
            $this->info('No store is due to be read.');
        }

        return self::SUCCESS;
    }
}
