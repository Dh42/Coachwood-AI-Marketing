<?php

namespace App\Livewire\BrandKit;

use App\BrandKit\BrandKits;
use App\BrandKit\Field;
use App\BrandKit\Kind;
use App\Models\Brand;
use App\Models\BrandKitItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One kind of item in the kit (logos, colours, fonts…): its items as rows of
 * fields, uploads for the kinds that carry a file, and removal.
 */
class KitItems extends Component
{
    use WithFileUploads;

    #[Locked]
    public Brand $brand;

    /** The kind's value (App\BrandKit\Kind); the view gets the enum as $kind. */
    #[Locked]
    public string $kindKey;

    #[Locked]
    public bool $canManage = false;

    /** @var array<string, array<string, mixed>> Each item's fields, keyed "i{id}" (saved) or "n…" (not saved yet). */
    public array $rows = [];

    /** @var list<TemporaryUploadedFile> New files, stored as soon as they arrive. */
    public array $uploads = [];

    /** @var array<string, TemporaryUploadedFile|null> A file for one row (a font's file). */
    public array $rowUploads = [];

    /** The row waiting for "Yes, remove". */
    public ?string $removing = null;

    public function mount(Brand $brand, Kind|string $kind, bool $canManage): void
    {
        $this->brand = $brand;
        $this->kindKey = $kind instanceof Kind ? $kind->value : $kind;
        $this->canManage = $canManage;
        $this->load();
    }

    public function kind(): Kind
    {
        return Kind::from($this->kindKey);
    }

    /**
     * New files are kept the moment they're chosen, each as its own item.
     */
    public function updatedUploads(BrandKits $kits): void
    {
        $this->authorize('brands.manage', $this->brand);
        $this->resetErrorBag('uploads');
        $added = 0;

        foreach ($this->uploads as $file) {
            try {
                $kits->addFile($this->brand, $this->kind(), $file, Auth::user());
                $added++;
            } catch (ValidationException $e) {
                $this->addError('uploads', $file->getClientOriginalName().': '.$e->validator->errors()->first());
            }
        }

        $this->uploads = [];
        $this->load();

        if ($added > 0) {
            $this->dispatch('saved', message: "Added {$added} ".Str::plural($this->kind()->one(), $added).'.');
            $this->dispatch('kit-changed');
        }
    }

    /**
     * A file chosen for one row goes on that item at once.
     */
    public function updatedRowUploads(mixed $file, string $key, BrandKits $kits): void
    {
        $this->authorize('brands.manage', $this->brand);
        $this->resetErrorBag("rowUploads.{$key}");
        $id = (int) ($this->rows[$key]['id'] ?? 0);
        $item = $id ? BrandKitItem::where('brand_id', $this->brand->id)->find($id) : null;

        if (! $file || ! $item) {
            $this->addError("rowUploads.{$key}", 'Save the row first, then add its file.');
            unset($this->rowUploads[$key]);

            return;
        }

        try {
            $kits->attachFile($item, $file, Auth::user());
            $this->dispatch('saved', message: "Added {$item->original_name}.");
            $this->dispatch('kit-changed');
        } catch (ValidationException $e) {
            $this->addError("rowUploads.{$key}", $e->validator->errors()->first());
        }

        unset($this->rowUploads[$key]);
    }

    public function add(): void
    {
        $this->authorize('brands.manage', $this->brand);
        $this->rows['n'.Str::lower(Str::random(8))] = $this->blankRow();
    }

    public function askRemove(string $key): void
    {
        $this->removing = $key;
    }

    public function keep(): void
    {
        $this->removing = null;
    }

    public function removeRow(string $key, BrandKits $kits): void
    {
        $this->authorize('brands.manage', $this->brand);
        $this->removing = null;

        if ($id = (int) ($this->rows[$key]['id'] ?? 0)) {
            $item = BrandKitItem::where('brand_id', $this->brand->id)->find($id);
            if ($item) {
                $label = $item->label();
                $kits->remove($item, Auth::user());
                $this->dispatch('saved', message: "Removed {$label}.");
                $this->dispatch('kit-changed');
            }
        }

        unset($this->rows[$key]);
        $this->resetErrorBag();
    }

    public function save(BrandKits $kits): void
    {
        $this->authorize('brands.manage', $this->brand);
        $this->resetErrorBag();

        try {
            $kits->saveRows($this->brand, $this->kind(), $this->rows, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->validator->errors()->messages() as $path => $messages) {
                $this->addError("rows.{$path}", $messages[0]);
            }

            return;
        }

        $this->load();
        $this->dispatch('saved', message: 'Saved '.strtolower($this->kind()->title()).'.');
        $this->dispatch('kit-changed');
    }

    public function render(BrandKits $kits)
    {
        $kind = $this->kind();
        $items = $kits->items($this->brand, $kind)->keyBy('id');

        return view('livewire.brand-kit.kit-items', [
            'kind' => $kind,
            'spec' => $kind->file(),
            'fields' => $kind->fields(),
            'options' => collect($kind->fields())->mapWithKeys(fn (Field $f) => [$f->key => $f->optionsFor($this->brand)])->all(),
            'items' => $items,
            'gallery' => in_array($kind, [Kind::Logo, Kind::Reference, Kind::BestAd, Kind::BadExample, Kind::PackShot], true),
        ]);
    }

    private function load(): void
    {
        $this->rows = [];
        foreach (app(BrandKits::class)->items($this->brand, $this->kind()) as $item) {
            $row = ['id' => $item->id];
            foreach ($this->kind()->fields() as $field) {
                $row[$field->key] = $item->value($field->key) ?? $field->blank();
                if ($field->key === 'product_variant_id') {
                    $row[$field->key] = $item->product_variant_id ? (string) $item->product_variant_id : '';
                }
            }
            $this->rows["i{$item->id}"] = $row;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function blankRow(): array
    {
        $row = ['id' => null];
        foreach ($this->kind()->fields() as $field) {
            $row[$field->key] = $field->blank();
        }

        return $row;
    }
}
