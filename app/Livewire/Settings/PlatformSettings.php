<?php

namespace App\Livewire\Settings;

use App\Plugins\CorePluginRegistry;
use App\Settings\SettingsAreas;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Settings')]
class PlatformSettings extends Component
{
    public function render()
    {
        return view('livewire.settings.platform-settings', [
            'corePlugins' => app(CorePluginRegistry::class)->all(),
            'homed' => app(SettingsAreas::class)->homedSections(),
        ]);
    }
}
