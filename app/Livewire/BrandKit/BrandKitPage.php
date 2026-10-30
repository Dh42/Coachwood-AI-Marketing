<?php

namespace App\Livewire\BrandKit;

use App\BrandKit\BrandKits;
use App\BrandKit\Kind;
use App\Models\Brand;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The brand kit: what the marketing team fills in before the brand can
 * generate. A checklist at the top, the voice and visual direction, then
 * every kind of item (App\BrandKit\Kind) in its own section.
 */
#[Title('Brand kit')]
class BrandKitPage extends Component
{
    #[Locked]
    public Brand $brand;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
    }

    /**
     * A section saved something: the checklist is drawn again.
     */
    #[On('kit-changed')]
    public function refreshChecklist(): void {}

    public function render(BrandKits $kits)
    {
        return view('livewire.brand-kit.brand-kit-page', [
            'checks' => $kits->readiness($this->brand),
            'ready' => $kits->isReady($this->brand),
            'groups' => Kind::groups(),
            'canManage' => Auth::user()->can('brands.manage', $this->brand),
        ]);
    }
}
