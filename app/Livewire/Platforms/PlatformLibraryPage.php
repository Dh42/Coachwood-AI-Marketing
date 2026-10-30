<?php

namespace App\Livewire\Platforms;

use App\Models\Placement;
use App\Models\PlacementPreset;
use App\Models\Platform;
use App\Platforms\CopyField;
use App\Platforms\PlatformLibrary;
use App\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The platform library: every platform and placement the team can ask
 * for, with the specs the composer, the checks and the copy writer use, and
 * the sets that tick several at once. Admins edit; a changed spec is a
 * changed row.
 */
#[Title('Platforms')]
class PlatformLibraryPage extends Component
{
    /** 'new' or a platform id, while its form is open. */
    public ?string $platformOpen = null;

    /** @var array{name: string, prompt_note: string} */
    public array $platformForm = ['name' => '', 'prompt_note' => ''];

    /** 'new-{platform id}' or a placement id, while its form is open. */
    public ?string $placementOpen = null;

    /** @var array<string, mixed> */
    public array $placementForm = [];

    /** 'new' or a preset id, while its form is open. */
    public ?string $presetOpen = null;

    /** @var array{name: string, placement_ids: list<string>} */
    public array $presetForm = ['name' => '', 'placement_ids' => []];

    /** What's waiting for "Yes, remove": platform-{id}, placement-{id} or preset-{id}. */
    public ?string $removing = null;

    public function mount(): void
    {
        $this->authorize('platforms.manage');
    }

    public function addStarters(PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        $added = $library->addStarters(Auth::user());
        $this->dispatch('saved', message: $added > 0 ? "Added {$added} platforms with their placements and sets. They're marked as not checked yet." : 'Every starting platform is already here.');
    }

    public function openPlatform(?int $id = null): void
    {
        $this->closeAll();
        $platform = $id ? Platform::findOrFail($id) : null;
        $this->platformOpen = $platform ? (string) $platform->id : 'new';
        $this->platformForm = ['name' => $platform?->name ?? '', 'prompt_note' => $platform?->prompt_note ?? ''];
    }

    public function savePlatform(PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        $platform = $this->platformOpen && $this->platformOpen !== 'new' ? Platform::findOrFail((int) $this->platformOpen) : null;

        try {
            $saved = $library->savePlatform($platform, $this->platformForm, Auth::user());
        } catch (ValidationException $e) {
            $this->errorsUnder('platformForm', $e);

            return;
        }

        $this->closeAll();
        $this->dispatch('saved', message: "Saved {$saved->name}.");
    }

    public function togglePlatform(int $id, PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        $platform = Platform::findOrFail($id);
        $library->enablePlatform($platform, ! $platform->enabled, Auth::user());
    }

    public function markChecked(int $id, bool $checked, PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        $library->markChecked(Platform::findOrFail($id), $checked, Auth::user());
    }

    public function openPlacement(int $platformId, ?int $id = null): void
    {
        $this->closeAll();
        $placement = $id ? Placement::where('platform_id', $platformId)->findOrFail($id) : null;
        $this->placementOpen = $placement ? (string) $placement->id : "new-{$platformId}";
        $this->placementForm = [
            'name' => $placement?->name ?? '',
            'width' => $placement?->width ?? '',
            'height' => $placement?->height ?? '',
            'formats' => $placement?->formats ?? [Placement::IMAGE],
            'safe_top' => $placement?->safe_top ?? 0, 'safe_bottom' => $placement?->safe_bottom ?? 0, 'safe_left' => $placement?->safe_left ?? 0, 'safe_right' => $placement?->safe_right ?? 0,
            'image_types' => $placement?->image_types ?? ['jpg', 'png'],
            'max_image_kb' => $placement?->max_image_kb ?? '',
            'video_types' => $placement?->video_types ?? ['mp4', 'mov'],
            'max_video_mb' => $placement?->max_video_mb ?? '',
            'video_min_seconds' => $placement?->video_min_seconds ?? '',
            'video_max_seconds' => $placement?->video_max_seconds ?? '',
            'copy_limits' => collect(CopyField::cases())->mapWithKeys(fn (CopyField $f) => [$f->value => $placement?->copyLimit($f) ?? ''])->all(),
            'notes' => $placement?->notes ?? '',
        ];
    }

    public function savePlacement(PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        [$platform, $placement] = $this->openPlacementModels();

        try {
            $saved = $library->savePlacement($platform, $placement, $this->placementForm, Auth::user());
        } catch (ValidationException $e) {
            $this->errorsUnder('placementForm', $e);

            return;
        }

        $this->closeAll();
        $this->dispatch('saved', message: "Saved {$platform->name} · {$saved->name}.");
    }

    public function togglePlacement(int $id, PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        $placement = Placement::findOrFail($id);
        $library->enablePlacement($placement, ! $placement->enabled, Auth::user());
    }

    public function openPreset(?int $id = null): void
    {
        $this->closeAll();
        $preset = $id ? PlacementPreset::findOrFail($id) : null;
        $this->presetOpen = $preset ? (string) $preset->id : 'new';
        $this->presetForm = ['name' => $preset?->name ?? '', 'placement_ids' => array_map('strval', $preset?->placement_ids ?? [])];
    }

    public function savePreset(PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        $preset = $this->presetOpen && $this->presetOpen !== 'new' ? PlacementPreset::findOrFail((int) $this->presetOpen) : null;

        try {
            $saved = $library->savePreset($preset, $this->presetForm['name'], $this->presetForm['placement_ids'], Auth::user());
        } catch (ValidationException $e) {
            $this->errorsUnder('presetForm', $e);

            return;
        }

        $this->closeAll();
        $this->dispatch('saved', message: "Saved the set {$saved->name}.");
    }

    public function closeAll(): void
    {
        $this->platformOpen = $this->placementOpen = $this->presetOpen = $this->removing = null;
        $this->resetErrorBag();
    }

    public function askRemove(string $key): void
    {
        $this->removing = $key;
    }

    public function keep(): void
    {
        $this->removing = null;
    }

    public function remove(string $key, PlatformLibrary $library): void
    {
        $this->authorize('platforms.manage');
        [$type, $id] = explode('-', $key) + [null, null];
        $this->removing = null;

        match ($type) {
            'platform' => $library->deletePlatform(Platform::findOrFail((int) $id), Auth::user()),
            'placement' => $library->deletePlacement(Placement::findOrFail((int) $id), Auth::user()),
            'preset' => $library->deletePreset(PlacementPreset::findOrFail((int) $id), Auth::user()),
            default => abort(404),
        };

        $this->closeAll();
        $this->dispatch('saved', message: 'Removed.');
    }

    public function render(PlatformLibrary $library)
    {
        $platforms = $library->platforms();

        return view('livewire.platforms.platform-library-page', [
            'platforms' => $platforms,
            'presets' => $library->presets(),
            'unchecked' => $platforms->reject->isChecked()->count(),
            'copyFields' => CopyField::cases(),
            'tz' => app(Settings::class)->get('platform.timezone'),
        ]);
    }

    /**
     * @return array{0: Platform, 1: ?Placement}
     */
    private function openPlacementModels(): array
    {
        if (str_starts_with((string) $this->placementOpen, 'new-')) {
            return [Platform::findOrFail((int) substr($this->placementOpen, 4)), null];
        }
        $placement = Placement::with('platform')->findOrFail((int) $this->placementOpen);

        return [$placement->platform, $placement];
    }

    private function errorsUnder(string $prefix, ValidationException $e): void
    {
        foreach ($e->validator->errors()->messages() as $path => $messages) {
            $this->addError("{$prefix}.{$path}", $messages[0]);
        }
    }
}
