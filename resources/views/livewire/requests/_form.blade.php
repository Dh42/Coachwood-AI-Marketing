{{-- The request's fields. Expects $platforms, $sets, $models, $perRatioDefault, $variant, $matches; properties $form and $productSearch. --}}
<x-form.field label="What do you need?" name="form.brief" help="Plain words, as you'd tell a designer: the product, the offer, the feeling, anything that must be in it.">
    <x-form.textarea wire:model="form.brief" id="form.brief" rows="4" placeholder="Marketing image for a sale on whey protein isolate, 20% off this weekend." />
</x-form.field>

<x-form.field label="Placements" name="form.placement_ids" help="Where it will run. One image is generated per shape and then sized for each placement.">
    @if ($sets->isNotEmpty())
        <div class="mb-2 flex flex-wrap gap-1.5">
            @foreach ($sets as $set)
                <x-button type="button" size="sm" variant="secondary" wire:click="tickSet({{ $set->id }})">Tick {{ $set->name }}</x-button>
            @endforeach
        </div>
    @endif
    <div class="grid gap-3 sm:grid-cols-2">
        @forelse ($platforms as $platform)
            <div wire:key="platform-{{ $platform->id }}" class="rounded-md border border-zinc-200 p-3 dark:border-zinc-800">
                <div class="font-medium">{{ $platform->name }}</div>
                <div class="mt-1 space-y-0.5">
                    @foreach ($platform->placements as $placement)
                        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="form.placement_ids" value="{{ $placement->id }}" class="rounded border-zinc-300 dark:border-zinc-700"> {{ $placement->name }} <span class="text-xs text-zinc-500">{{ $placement->sizeLabel() }}</span></label>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-zinc-500 dark:text-zinc-400">No platforms are set up yet. An admin adds them on the Platforms page.</p>
        @endforelse
    </div>
</x-form.field>

<div class="grid gap-4 sm:grid-cols-2">
    <x-form.field label="What to make" name="form.output">
        <div class="space-y-1">
            @foreach (\App\Models\CreativeRequest::OUTPUTS as $value => $label)
                <label class="flex items-center gap-1.5"><input type="radio" wire:model.live="form.output" value="{{ $value }}" class="border-zinc-300 dark:border-zinc-700"> {{ $label }}</label>
            @endforeach
        </div>
    </x-form.field>
    @if (($form['output'] ?? 'ad') !== 'image')
        <x-form.field label="How the words go on" name="form.ad_style">
            <div class="space-y-1">
                @foreach (\App\Models\CreativeRequest::AD_STYLES as $value => $label)
                    <label class="flex items-start gap-1.5"><input type="radio" wire:model="form.ad_style" value="{{ $value }}" class="mt-1 border-zinc-300 dark:border-zinc-700"> <span>{{ $label }}</span></label>
                @endforeach
            </div>
        </x-form.field>
    @endif
</div>

<div class="grid gap-4 sm:grid-cols-3">
    <x-form.field label="Copy" name="form.copy">
        <x-form.select wire:model="form.copy" id="form.copy">
            @foreach (\App\Models\CreativeRequest::COPY as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </x-form.select>
    </x-form.field>
    <x-form.field label="Languages" name="form.languages">
        <div class="space-y-1 pt-1">
            @foreach (\App\Models\CreativeRequest::LANGUAGES as $value => $label)
                <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="form.languages" value="{{ $value }}" class="rounded border-zinc-300 dark:border-zinc-700"> {{ $label }}</label>
            @endforeach
        </div>
    </x-form.field>
    <x-form.field label="Images per shape" name="form.per_ratio" help="The brand's setting is {{ $perRatioDefault }}.">
        <x-form.input type="number" min="1" max="20" wire:model="form.per_ratio" id="form.per_ratio" placeholder="{{ $perRatioDefault }}" />
    </x-form.field>
</div>

<x-form.field label="Deliver the best" name="form.deliver" help="How many of what passes review you see per shape and per placement. The brand's setting is {{ $deliverDefault }}; the rest stays under Rejected with its reason." class="max-w-xs">
    <x-form.input type="number" min="1" max="20" wire:model="form.deliver" id="form.deliver" placeholder="{{ $deliverDefault }}" />
</x-form.field>

<x-form.field label="The product, if you already know it" name="form.product_variant_id" help="Otherwise the planner finds it from the brief and asks when more than one fits.">
    @if ($variant)
        <div class="flex items-center gap-2">
            <span class="rounded-md bg-zinc-100 px-2 py-1 dark:bg-zinc-800">{{ $variant->product->title }}: {{ $variant->label() }}</span>
            <x-button type="button" size="sm" variant="ghost" wire:click="clearVariant">Clear</x-button>
        </div>
    @else
        <x-form.input type="search" wire:model.live.debounce.300ms="productSearch" placeholder="Search the store by name, flavour or size" class="max-w-md" />
        @if ($matches->isNotEmpty())
            <ul class="mt-2 space-y-1">
                @foreach ($matches as $product)
                    <li wire:key="match-{{ $product->id }}" class="text-sm">
                        <span class="font-medium">{{ $product->title }}</span>
                        @foreach ($product->variants as $v)
                            <x-button type="button" size="sm" variant="ghost" wire:click="chooseVariant({{ $v->id }})">{{ $v->label() }}</x-button>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        @elseif (mb_strlen(trim($productSearch)) >= 2)
            <p class="mt-1 text-xs text-zinc-500">Nothing in the store matches.</p>
        @endif
    @endif
</x-form.field>

<details class="rounded-md border border-zinc-200 p-3 dark:border-zinc-800" @if ($errors->has('form.image_model')) open @endif>
    <summary class="cursor-pointer font-medium select-none">Advanced</summary>
    <div class="mt-3">
        <x-form.field label="Image model" name="form.image_model" help="Leave it to the planner unless you want a particular one.">
            <x-form.select wire:model="form.image_model" id="form.image_model" class="max-w-sm">
                <option value="">Let the planner choose</option>
                @foreach ($models as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-form.select>
        </x-form.field>
    </div>
</details>
