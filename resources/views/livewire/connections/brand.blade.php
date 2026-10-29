<x-form.field label="Brand" name="brand" help="A brand's connections only show to that brand. Shared ones show everywhere.">
    <x-form.select wire:model="brand" id="brand">
        <option value="">Shared by every brand</option>
        @foreach (\App\Models\Brand::orderBy('name')->get() as $option)
            <option value="{{ $option->id }}">{{ $option->name }}</option>
        @endforeach
    </x-form.select>
</x-form.field>
