<?php

namespace App\Livewire\Layouts;

use App\Composer\ComposeException;
use App\Composer\Composer;
use App\Composer\Layouts;
use App\Models\Brand;
use App\Models\LayoutTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The brand's layouts: where the words, logo, product and button sit on
 * a finished ad, per shape, with a preview on the brand's kit.
 */
#[Title('Layouts')]
class LayoutsPage extends Component
{
    #[Locked]
    public Brand $brand;

    /** 'new' or a template id, while its form is open. */
    public ?string $open = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** A rendered preview of the open form, as a data URL. */
    public ?string $preview = null;

    public ?string $previewError = null;

    public ?int $removing = null;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
    }

    public function addStarters(Layouts $layouts): void
    {
        $this->authorize('brands.manage', $this->brand);
        $added = $layouts->addStarters($this->brand, Auth::user());
        $this->dispatch('saved', message: $added > 0 ? "Added a layout for {$added} ".Str::plural('shape', $added).'.' : 'Every shape already has a layout.');
    }

    public function openForm(?int $id = null, ?string $ratio = null): void
    {
        $this->authorize('brands.manage', $this->brand);
        $template = $id ? LayoutTemplate::where('brand_id', $this->brand->id)->findOrFail($id) : null;
        $this->open = $template ? (string) $template->id : 'new';
        $this->preview = null;
        $this->previewError = null;
        $this->resetErrorBag();
        $defaults = LayoutTemplate::defaults($template?->ratio ?? $ratio ?? '1:1');
        $this->form = ['name' => $template?->name ?? 'Standard', 'ratio' => $template?->ratio ?? $ratio ?? '1:1'];
        foreach ([...array_keys(LayoutTemplate::OPTIONS), ...array_keys(LayoutTemplate::TOGGLES)] as $key) {
            $this->form[$key] = $template?->setting($key) ?? $defaults[$key];
        }
    }

    public function save(Layouts $layouts): void
    {
        $this->authorize('brands.manage', $this->brand);
        $this->resetErrorBag();
        $template = $this->open && $this->open !== 'new' ? LayoutTemplate::where('brand_id', $this->brand->id)->findOrFail((int) $this->open) : null;

        try {
            $saved = $layouts->save($this->brand, $template, $this->form, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError("form.{$key}", $messages[0]);
            }

            return;
        }

        $this->open = null;
        $this->preview = null;
        $this->dispatch('saved', message: "Saved {$saved->name} ({$saved->ratio}).");
    }

    public function showPreview(Composer $composer): void
    {
        $this->authorize('brands.manage', $this->brand);
        $this->previewError = null;
        $settings = collect($this->form)->except(['name', 'ratio'])->all();
        $template = new LayoutTemplate(['brand_id' => $this->brand->id, 'ratio' => (string) ($this->form['ratio'] ?? '1:1'), 'name' => 'Preview', 'settings' => $settings]);

        try {
            $this->preview = 'data:image/png;base64,'.base64_encode($composer->preview($this->brand, $template));
        } catch (ComposeException $e) {
            $this->previewError = $e->getMessage();
        }
    }

    public function duplicate(int $id, Layouts $layouts): void
    {
        $this->authorize('brands.manage', $this->brand);
        $layouts->duplicate(LayoutTemplate::where('brand_id', $this->brand->id)->findOrFail($id), Auth::user());
    }

    public function makeDefault(int $id, Layouts $layouts): void
    {
        $this->authorize('brands.manage', $this->brand);
        $layouts->makeDefault(LayoutTemplate::where('brand_id', $this->brand->id)->findOrFail($id), Auth::user());
    }

    public function askRemove(int $id): void
    {
        $this->removing = $id;
    }

    public function keep(): void
    {
        $this->removing = null;
    }

    public function remove(int $id, Layouts $layouts): void
    {
        $this->authorize('brands.manage', $this->brand);
        $layouts->delete(LayoutTemplate::where('brand_id', $this->brand->id)->findOrFail($id), Auth::user());
        $this->removing = null;
    }

    public function close(): void
    {
        $this->open = null;
        $this->preview = null;
        $this->resetErrorBag();
    }

    public function render(Layouts $layouts)
    {
        return view('livewire.layouts.layouts-page', [
            'templates' => $layouts->for($this->brand)->groupBy('ratio'),
            'ratios' => $layouts->ratios(),
            'canManage' => Auth::user()->hasPermission('brands.manage', $this->brand->id),
        ]);
    }
}
