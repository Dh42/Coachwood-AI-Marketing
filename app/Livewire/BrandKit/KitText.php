<?php

namespace App\Livewire\BrandKit;

use App\BrandKit\BrandKits;
use App\Models\Brand;
use App\Models\BrandKit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The written part of the kit: the brand's voice (in each language) and its
 * visual direction.
 */
class KitText extends Component
{
    #[Locked]
    public Brand $brand;

    #[Locked]
    public bool $canManage = false;

    /** @var array<string, array{en: string, fr: string}> */
    public array $voice = [];

    /** @var array<string, string> */
    public array $direction = [];

    public function mount(Brand $brand, bool $canManage): void
    {
        $this->brand = $brand;
        $this->canManage = $canManage;
        $this->load();
    }

    public function save(BrandKits $kits): void
    {
        $this->authorize('brands.manage', $this->brand);

        try {
            $kits->saveText($this->brand, $this->voice, $this->direction, Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->resetErrorBag();
        $this->load();
        $this->dispatch('saved', message: 'Saved the voice and visual direction.');
        $this->dispatch('kit-changed');
    }

    public function render()
    {
        return view('livewire.brand-kit.kit-text');
    }

    private function load(): void
    {
        $kit = app(BrandKits::class)->for($this->brand);
        $blank = BrandKit::blank();

        foreach (BrandKit::VOICE as $part) {
            $this->voice[$part] = ['en' => $kit->voice($part), 'fr' => $kit->voice($part, 'fr')];
        }
        foreach (BrandKit::DIRECTION as $part) {
            $this->direction[$part] = $kit->direction($part);
        }
        $this->voice = [...$blank['voice'], ...$this->voice];
        $this->direction = [...$blank['direction'], ...$this->direction];
    }
}
