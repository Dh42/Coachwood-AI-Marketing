<?php

namespace App\Brands;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Creating and changing brands. A brand is one store's marketing: its own
 * Shopify catalogue, brand kit, prompts, templates and settings.
 */
class Brands
{
    /**
     * @param  array{name: string, color?: ?string, slug?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $by): Brand
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80', Rule::unique('brands', 'name')],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'slug' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('brands', 'slug')],
        ], [
            'name.unique' => 'There is already a brand with this name.',
            'slug.unique' => 'That ID is already taken.',
            'slug.regex' => 'An ID is lowercase letters, numbers and dashes, like canadian-protein.',
        ])->validate();

        $slug = $data['slug'] ?? null ?: $this->uniqueSlug($data['name']);

        $brand = Brand::create([
            'slug' => $slug,
            'name' => trim($data['name']),
            'color' => $data['color'] ?? '#18181b',
            'created_by' => $by->id,
        ]);

        activity('brands')->causedBy($by)->performedOn($brand)->event('created')->log("Created the brand \"{$brand->name}\"");

        return $brand;
    }

    /**
     * @param  array{name: string, color?: ?string}  $input
     *
     * @throws ValidationException
     */
    public function update(Brand $brand, array $input, User $by): Brand
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:80', Rule::unique('brands', 'name')->ignore($brand->id)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], ['name.unique' => 'There is already a brand with this name.'])->validate();

        $old = $brand->only(['name', 'color']);
        $brand->update(['name' => trim($data['name']), 'color' => $data['color'] ?? $brand->color]);

        if ($brand->wasChanged()) {
            activity('brands')->causedBy($by)->performedOn($brand)->event('updated')
                ->withProperties(['old' => $old, 'new' => $brand->only(['name', 'color'])])
                ->log("Changed the brand \"{$brand->name}\"");
        }

        return $brand;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;

        for ($n = 2; Brand::where('slug', $slug)->exists(); $n++) {
            if ($n > 1000) {
                throw new LogicException('Could not find a free brand ID.');
            }
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }
}
