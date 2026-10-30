<?php

namespace App\Console\Commands;

use App\Prompts\PromptTrials;
use App\Review\Retention;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('outputs:prune-rejects')]
#[Description('Delete the files of rejected outputs kept longer than each brand\'s "Keep rejected outputs for" setting, and images tried on the Prompts page more than a day ago')]
class PruneRejects extends Command
{
    public function handle(Retention $retention, PromptTrials $trials): int
    {
        $removed = $retention->prune();
        $tried = $trials->prune();

        $this->info($removed > 0 ? "Removed the files of {$removed} rejected ".str('output')->plural($removed).'.' : 'Nothing was due to be removed.');
        if ($tried > 0) {
            $this->info("Removed {$tried} ".str('image')->plural($tried).' tried on the Prompts page.');
        }

        return self::SUCCESS;
    }
}
