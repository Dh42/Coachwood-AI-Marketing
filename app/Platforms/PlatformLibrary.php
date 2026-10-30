<?php

namespace App\Platforms;

use App\Models\Placement;
use App\Models\PlacementPreset;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The platform library: platforms and their placements (sizes, safe zones,
 * file rules, video lengths, copy limits) and presets. Editable rows, so a
 * changed spec is a changed row, not a deploy.
 */
class PlatformLibrary
{
    /**
     * @return Collection<int, Platform>
     */
    public function platforms(bool $availableOnly = false): Collection
    {
        return Platform::with(['placements' => fn ($q) => $availableOnly ? $q->where('enabled', true) : $q, 'verifier'])
            ->when($availableOnly, fn ($q) => $q->where('enabled', true))
            ->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Placements the team can pick, with their platforms.
     *
     * @return Collection<int, Placement>
     */
    public function available(): Collection
    {
        return Placement::with('platform')->available()->get()
            ->sortBy(fn (Placement $p) => [$p->platform->position, $p->platform->id, $p->position, $p->id])->values();
    }

    /**
     * The placements grouped by the aspect ratio they're generated at: one
     * generation per ratio serves every placement of that shape.
     *
     * @param  Collection<int, Placement>  $placements
     * @return array<string, Collection<int, Placement>>
     */
    public function byRatio(Collection $placements): array
    {
        return $placements->groupBy('ratio')->sortKeys()->all();
    }

    /**
     * @param  array{name: string, prompt_note?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function savePlatform(?Platform $platform, array $input, User $by): Platform
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80', Rule::unique('platforms', 'name')->ignore($platform?->id)],
            'prompt_note' => ['nullable', 'string', 'max:1000'],
        ], ['name.unique' => 'There is already a platform with this name.'])->validate();

        $platform ??= new Platform(['key' => $this->uniqueKey(Platform::query(), Str::slug($data['name'])), 'enabled' => true, 'position' => (int) Platform::max('position') + 1]);
        $new = ! $platform->exists;
        $platform->fill(['name' => trim($data['name']), 'prompt_note' => trim((string) ($data['prompt_note'] ?? '')) ?: null])->save();

        if ($new || $platform->wasChanged()) {
            activity('platforms')->causedBy($by)->performedOn($platform)->event($new ? 'platform_added' : 'platform_changed')->log(($new ? 'Added the platform ' : 'Changed the platform ')."\"{$platform->name}\"");
        }

        return $platform;
    }

    public function enablePlatform(Platform $platform, bool $enabled, User $by): void
    {
        if ($platform->enabled !== $enabled) {
            $platform->update(['enabled' => $enabled]);
            activity('platforms')->causedBy($by)->performedOn($platform)->event($enabled ? 'platform_on' : 'platform_off')->log(($enabled ? 'Switched on ' : 'Switched off ')."\"{$platform->name}\"");
        }
    }

    /**
     * Records that someone checked the platform's rows against its own docs.
     */
    public function markChecked(Platform $platform, bool $checked, User $by): void
    {
        $platform->update(['verified_at' => $checked ? now() : null, 'verified_by' => $checked ? $by->id : null]);
        activity('platforms')->causedBy($by)->performedOn($platform)->event($checked ? 'checked' : 'unchecked')
            ->log($checked ? "Marked \"{$platform->name}\" as checked against its docs" : "Marked \"{$platform->name}\" as not checked");
    }

    public function deletePlatform(Platform $platform, User $by): void
    {
        $name = $platform->name;
        $ids = $platform->placements()->pluck('id')->all();
        $platform->delete();
        $this->forgetFromPresets($ids);
        activity('platforms')->causedBy($by)->event('platform_removed')->log("Removed the platform \"{$name}\"");
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function savePlacement(Platform $platform, ?Placement $placement, array $input, User $by): Placement
    {
        $input['formats'] = array_values(array_filter((array) ($input['formats'] ?? [])));
        $input['image_types'] = array_values(array_filter((array) ($input['image_types'] ?? [])));
        $input['video_types'] = array_values(array_filter((array) ($input['video_types'] ?? [])));
        $takesImages = in_array(Placement::IMAGE, $input['formats'], true);
        $takesVideo = in_array(Placement::VIDEO, $input['formats'], true);

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80'],
            'width' => ['required', 'integer', 'min:100', 'max:8000'],
            'height' => ['required', 'integer', 'min:100', 'max:8000'],
            'formats' => ['required', 'array', 'min:1'],
            'formats.*' => [Rule::in([Placement::IMAGE, Placement::VIDEO])],
            'safe_top' => ['nullable', 'integer', 'min:0', 'max:8000'],
            'safe_bottom' => ['nullable', 'integer', 'min:0', 'max:8000'],
            'safe_left' => ['nullable', 'integer', 'min:0', 'max:8000'],
            'safe_right' => ['nullable', 'integer', 'min:0', 'max:8000'],
            'image_types' => [$takesImages ? 'required' : 'nullable', 'array', $takesImages ? 'min:1' : 'max:99'],
            'image_types.*' => [Rule::in(Placement::IMAGE_TYPES)],
            'max_image_kb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'video_types' => [$takesVideo ? 'required' : 'nullable', 'array', $takesVideo ? 'min:1' : 'max:99'],
            'video_types.*' => [Rule::in(Placement::VIDEO_TYPES)],
            'max_video_mb' => ['nullable', 'integer', 'min:1', 'max:10240'],
            'video_min_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'video_max_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'copy_limits' => ['nullable', 'array'],
            'copy_limits.*' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'formats.required' => 'Tick image, video or both.',
            'formats.min' => 'Tick image, video or both.',
            'image_types.required' => 'Tick at least one image file type.',
            'image_types.min' => 'Tick at least one image file type.',
            'video_types.required' => 'Tick at least one video file type.',
            'video_types.min' => 'Tick at least one video file type.',
        ], ['width' => 'width', 'height' => 'height', 'video_min_seconds' => 'shortest video', 'video_max_seconds' => 'longest video'])->validate();

        if (! empty($data['video_min_seconds']) && ! empty($data['video_max_seconds']) && (int) $data['video_max_seconds'] < (int) $data['video_min_seconds']) {
            throw ValidationException::withMessages(['video_max_seconds' => 'The longest video can\'t be shorter than the shortest.']);
        }
        if (($data['safe_top'] ?? 0) + ($data['safe_bottom'] ?? 0) >= $data['height'] || ($data['safe_left'] ?? 0) + ($data['safe_right'] ?? 0) >= $data['width']) {
            throw ValidationException::withMessages(['safe_top' => 'The safe zone leaves no room: the edges add up to more than the size.']);
        }

        $copy = [];
        foreach (CopyField::cases() as $field) {
            if ((int) ($data['copy_limits'][$field->value] ?? 0) > 0) {
                $copy[$field->value] = (int) $data['copy_limits'][$field->value];
            }
        }

        $placement ??= new Placement(['platform_id' => $platform->id, 'key' => $this->uniqueKey($platform->placements()->getQuery(), Str::slug($data['name'])), 'enabled' => true, 'position' => (int) $platform->placements()->max('position') + 1]);
        $new = ! $placement->exists;
        $placement->fill([
            'name' => trim($data['name']),
            'width' => (int) $data['width'],
            'height' => (int) $data['height'],
            'formats' => $data['formats'],
            'safe_top' => (int) ($data['safe_top'] ?? 0), 'safe_bottom' => (int) ($data['safe_bottom'] ?? 0), 'safe_left' => (int) ($data['safe_left'] ?? 0), 'safe_right' => (int) ($data['safe_right'] ?? 0),
            'image_types' => $takesImages ? $data['image_types'] : [],
            'max_image_kb' => $takesImages && ! empty($data['max_image_kb']) ? (int) $data['max_image_kb'] : null,
            'video_types' => $takesVideo ? $data['video_types'] : [],
            'max_video_mb' => $takesVideo && ! empty($data['max_video_mb']) ? (int) $data['max_video_mb'] : null,
            'video_min_seconds' => $takesVideo && ! empty($data['video_min_seconds']) ? (int) $data['video_min_seconds'] : null,
            'video_max_seconds' => $takesVideo && ! empty($data['video_max_seconds']) ? (int) $data['video_max_seconds'] : null,
            'copy_limits' => $copy,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ])->save();

        if ($new || $placement->wasChanged()) {
            activity('platforms')->causedBy($by)->performedOn($placement)->event($new ? 'placement_added' : 'placement_changed')
                ->log(($new ? 'Added the placement ' : 'Changed the placement ')."\"{$platform->name} · {$placement->name}\" ({$placement->sizeLabel()})");
        }

        return $placement;
    }

    public function enablePlacement(Placement $placement, bool $enabled, User $by): void
    {
        if ($placement->enabled !== $enabled) {
            $placement->update(['enabled' => $enabled]);
            activity('platforms')->causedBy($by)->performedOn($placement)->event($enabled ? 'placement_on' : 'placement_off')->log(($enabled ? 'Switched on ' : 'Switched off ')."\"{$placement->label()}\"");
        }
    }

    public function deletePlacement(Placement $placement, User $by): void
    {
        $label = $placement->label();
        $placement->delete();
        $this->forgetFromPresets([$placement->id]);
        activity('platforms')->causedBy($by)->event('placement_removed')->log("Removed the placement \"{$label}\"");
    }

    /**
     * @return Collection<int, PlacementPreset>
     */
    public function presets(): Collection
    {
        return PlacementPreset::orderBy('position')->orderBy('id')->get();
    }

    /**
     * @param  list<int|string>  $placementIds
     *
     * @throws ValidationException
     */
    public function savePreset(?PlacementPreset $preset, string $name, array $placementIds, User $by): PlacementPreset
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($placementIds))));
        Validator::make(['name' => $name, 'placement_ids' => $ids], [
            'name' => ['required', 'string', 'max:80', Rule::unique('placement_presets', 'name')->ignore($preset?->id)],
            'placement_ids' => ['required', 'array', 'min:1'],
            'placement_ids.*' => [Rule::exists('placements', 'id')],
        ], ['name.unique' => 'There is already a set with this name.', 'placement_ids.required' => 'Tick at least one placement.', 'placement_ids.min' => 'Tick at least one placement.'])->validate();

        $preset ??= new PlacementPreset(['position' => (int) PlacementPreset::max('position') + 1]);
        $new = ! $preset->exists;
        $preset->fill(['name' => trim($name), 'placement_ids' => $ids])->save();

        activity('platforms')->causedBy($by)->performedOn($preset)->event($new ? 'preset_added' : 'preset_changed')->log(($new ? 'Added the set ' : 'Changed the set ')."\"{$preset->name}\" (".count($ids).' placements)');

        return $preset;
    }

    public function deletePreset(PlacementPreset $preset, User $by): void
    {
        $name = $preset->name;
        $preset->delete();
        activity('platforms')->causedBy($by)->event('preset_removed')->log("Removed the set \"{$name}\"");
    }

    /**
     * Adds the starting platforms, placements and sets that aren't there
     * yet (by key), leaving edited rows alone. Returns how many platforms
     * were added.
     */
    public function addStarters(User $by): int
    {
        return DB::transaction(function () use ($by) {
            $added = 0;
            $position = (int) Platform::max('position');

            foreach (Starters::platforms() as $data) {
                $platform = Platform::firstOrNew(['key' => $data['key']]);
                if (! $platform->exists) {
                    $platform->fill(['name' => $data['name'], 'prompt_note' => $data['prompt_note'], 'enabled' => true, 'position' => ++$position])->save();
                    $added++;
                }
                foreach ($data['placements'] as $i => $row) {
                    if (! $platform->placements()->where('key', $row['key'])->exists()) {
                        $platform->placements()->create([...$row, 'enabled' => true, 'position' => $i]);
                    }
                }
            }

            foreach (Starters::presets() as $name => $keys) {
                if (PlacementPreset::where('name', $name)->exists()) {
                    continue;
                }
                $ids = [];
                foreach ($keys as $platformKey => $placementKeys) {
                    $ids = [...$ids, ...Placement::whereHas('platform', fn ($q) => $q->where('key', $platformKey))->whereIn('key', $placementKeys)->pluck('id')->all()];
                }
                if ($ids !== []) {
                    PlacementPreset::create(['name' => $name, 'placement_ids' => $ids, 'position' => (int) PlacementPreset::max('position') + 1]);
                }
            }

            if ($added > 0) {
                activity('platforms')->causedBy($by)->event('starters_added')->log("Added the starting platforms ({$added})");
            }

            return $added;
        });
    }

    /**
     * @param  list<int>  $placementIds
     */
    private function forgetFromPresets(array $placementIds): void
    {
        foreach (PlacementPreset::all() as $preset) {
            $kept = array_values(array_diff($preset->placement_ids ?? [], $placementIds));
            if ($kept !== ($preset->placement_ids ?? [])) {
                $preset->update(['placement_ids' => $kept]);
            }
        }
    }

    private function uniqueKey(Builder $query, string $base): string
    {
        $base = $base ?: 'item';
        $key = $base;
        for ($n = 2; (clone $query)->where('key', $key)->exists(); $n++) {
            $key = "{$base}-{$n}";
        }

        return $key;
    }
}
