<?php

namespace App\Livewire\Settings;

use App\Models\Brand;
use App\Settings\SettingsAreas;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A feature's brand settings (SettingsArea), an entry in the brand's
 * Settings, also opened from the Settings button on the feature's page.
 */
class AreaSettingsPage extends Component
{
    #[Locked]
    public Brand $brand;

    #[Locked]
    public string $area;

    public function mount(Brand $brand, string $area): void
    {
        $found = app(SettingsAreas::class)->find($area);
        abort_unless($found && $found->settingsRoute === null && (! $found->when || ($found->when)($brand)), 404);
        $this->authorize($found->ability, $brand);

        $this->brand = $brand;
        $this->area = $area;
    }

    public function render()
    {
        $area = app(SettingsAreas::class)->find($this->area);

        return view('livewire.settings.area-settings-page', [
            'settingsArea' => $area,
            'back' => $area->pageRoute ? route($area->pageRoute, ['brand' => $this->brand]) : null,
            'canManage' => Auth::user()->can('brands.manage', $this->brand),
        ])->title("{$area->title} settings");
    }
}
