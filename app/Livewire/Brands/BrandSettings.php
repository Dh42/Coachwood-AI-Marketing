<?php

namespace App\Livewire\Brands;

use App\Brands\Brands;
use App\Models\Brand;
use App\Settings\SettingsAreas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The "Brand" entry of a brand's Settings: its name and colour, and the
 * brand-wide settings no feature has claimed (SettingsAreas::homedSections).
 */
class BrandSettings extends Component
{
    #[Locked]
    public Brand $brand;

    public string $name = '';

    public string $color = '';

    /** @var array{name: string, color: string} The saved values, for the save bar. */
    #[Locked]
    public array $savedGeneral = ['name' => '', 'color' => ''];

    public function mount(Brand $brand): void
    {
        $this->authorize('brands.view', $brand);

        $this->brand = $brand;
        $this->loadGeneral();
    }

    public function saveGeneral(Brands $brands): bool
    {
        $this->authorize('brands.manage', $this->brand);

        try {
            $brands->update($this->brand, ['name' => $this->name, 'color' => $this->color], Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return false;
        }

        $this->loadGeneral();
        $this->dispatch('saved', message: 'Saved.');

        return true;
    }

    public function discardGeneral(): void
    {
        $this->resetErrorBag();
        $this->loadGeneral();
    }

    public function render()
    {
        return view('livewire.brands.brand-settings', [
            'canManage' => Auth::user()->can('brands.manage', $this->brand),
            'homed' => app(SettingsAreas::class)->homedSections(),
        ])->title('Settings');
    }

    private function loadGeneral(): void
    {
        $this->name = $this->brand->name;
        $this->color = $this->brand->color;
        $this->savedGeneral = ['name' => $this->name, 'color' => $this->color];
    }
}
