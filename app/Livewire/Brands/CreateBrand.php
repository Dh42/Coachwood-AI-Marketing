<?php

namespace App\Livewire\Brands;

use App\Brands\Brands;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('New brand')]
class CreateBrand extends Component
{
    public string $name = '';

    public string $color = '#18181b';

    public function mount(): void
    {
        $this->authorize('brands.create');
    }

    public function save(Brands $brands)
    {
        $this->authorize('brands.create');

        try {
            $brand = $brands->create(['name' => $this->name, 'color' => $this->color], Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('status', "\"{$brand->name}\" is ready. Fill in its brand kit and connect its store next.");

        return $this->redirectRoute('brand-settings', $brand);
    }

    public function render()
    {
        return view('livewire.brands.create-brand');
    }
}
