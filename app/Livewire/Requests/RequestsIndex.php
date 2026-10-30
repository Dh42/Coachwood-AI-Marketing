<?php

namespace App\Livewire\Requests;

use App\Models\Brand;
use App\Models\CreativeRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The brand's requests: everyone's public ones, the person's own, and
 * all of them for admins.
 */
#[Title('Requests')]
class RequestsIndex extends Component
{
    use WithPagination;

    #[Locked]
    public Brand $brand;

    /** all or mine */
    #[Url]
    public string $show = 'all';

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
    }

    public function updatingShow(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $requests = CreativeRequest::with('user')->visibleTo(Auth::user(), $this->brand)
            ->when($this->show === 'mine', fn ($q) => $q->where('user_id', Auth::id()))
            ->latest('id')->paginate(25);

        return view('livewire.requests.requests-index', [
            'requests' => $requests,
            'canCreate' => Auth::user()->hasPermission('requests.create', $this->brand->id),
        ]);
    }
}
