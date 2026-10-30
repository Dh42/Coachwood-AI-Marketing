<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A set of placements the team ticks as one ("Meta full set", "All vertical video").
 */
#[Fillable(['name', 'placement_ids', 'position'])]
class PlacementPreset extends Model
{
    protected function casts(): array
    {
        return ['placement_ids' => 'array'];
    }

    /**
     * The placements in the set that can still be picked.
     *
     * @return Collection<int, Placement>
     */
    public function placements(): Collection
    {
        return Placement::with('platform')->available()->whereIn('id', $this->placement_ids ?? [])->get()
            ->sortBy(fn (Placement $p) => [$p->platform->position, $p->platform->id, $p->position, $p->id])->values();
    }
}
