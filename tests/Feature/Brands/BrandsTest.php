<?php

use App\Auth\Role;
use App\Brands\Brands;
use App\Livewire\Brands\BrandSettings;
use App\Livewire\Brands\CreateBrand;
use App\Models\Brand;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = signInAs(Role::Admin);
});

it('creates a brand with an ID made from its name, and logs it', function () {
    Livewire::test(CreateBrand::class)->set('name', 'Canadian Protein')->set('color', '#ff0000')->call('save')
        ->assertHasNoErrors()->assertRedirect(route('brand-settings', 'canadian-protein'));

    $brand = Brand::sole();
    expect($brand->slug)->toBe('canadian-protein')->and($brand->color)->toBe('#ff0000')->and($brand->created_by)->toBe($this->admin->id)
        ->and(Activity::where('log_name', 'brands')->sole()->description)->toBe('Created the brand "Canadian Protein"');
});

it('keeps IDs unique when two brands share a name after slugging', function () {
    app(Brands::class)->create(['name' => 'Bio Steel'], $this->admin);
    $second = app(Brands::class)->create(['name' => 'Bio-Steel'], $this->admin);

    expect($second->slug)->toBe('bio-steel-2');
});

it('refuses a duplicate name and a bad colour', function () {
    makeBrand(['name' => 'BioSteel']);

    Livewire::test(CreateBrand::class)->set('name', 'BioSteel')->call('save')->assertHasErrors('name');
    Livewire::test(CreateBrand::class)->set('name', 'Other')->set('color', 'red')->call('save')->assertHasErrors('color');
    expect(Brand::count())->toBe(1);
});

it('never changes a brand\'s ID', function () {
    $brand = makeBrand(['slug' => 'fixed']);
    $brand->slug = 'other';
    $brand->save();
})->throws(LogicException::class);

it('saves and discards the name and colour through the save bar, for those who may', function () {
    $brand = makeBrand(['name' => 'BS', 'color' => '#000000']);

    Livewire::test(BrandSettings::class, ['brand' => $brand])
        ->assertSet('savedGeneral.name', 'BS')
        ->set('name', 'BioSteel')->call('discardGeneral')->assertSet('name', 'BS')
        ->set('name', 'BioSteel')->set('color', '#112233')->call('saveGeneral')->assertReturned(true)
        ->assertSet('savedGeneral.name', 'BioSteel');

    expect($brand->fresh()->name)->toBe('BioSteel')->and($brand->fresh()->color)->toBe('#112233');

    signInAs(Role::Viewer);
    Livewire::test(BrandSettings::class, ['brand' => $brand])->set('name', 'X')->call('saveGeneral')->assertForbidden();
});

it('lets creators change a brand but not add one', function () {
    $brand = makeBrand();
    signInAs(Role::Creator);

    Livewire::test(BrandSettings::class, ['brand' => $brand])->set('name', 'Renamed')->call('saveGeneral')->assertReturned(true);
    $this->get(route('brands.create'))->assertForbidden();
});

it('validates updates too', function () {
    $brand = makeBrand();
    makeBrand(['name' => 'Taken']);

    app(Brands::class)->update($brand, ['name' => 'Taken'], $this->admin);
})->throws(ValidationException::class);
