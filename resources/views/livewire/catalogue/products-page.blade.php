<div class="max-w-5xl space-y-6" @if ($running) wire:poll.5s @endif>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-2xl">
            <p class="text-zinc-600 dark:text-zinc-400">
                What the store sells, as the app sees it. The planner finds products here from a brief, and each variant's pack shot and packaging size come from this list.
                @if ($store)
                    Read from <strong>{{ $store->name }}</strong>
                    @if ($lastSync)
                        · last {{ $lastSync->status === \App\Models\CatalogueSync::RUNNING ? 'started' : $lastSync->status }} {{ ($lastSync->finished_at ?? $lastSync->started_at)->diffForHumans() }}{{ $lastSync->error ? ": {$lastSync->error}" : '' }}.
                    @else
                        · not read yet.
                    @endif
                @else
                    No store is chosen yet: pick the brand's Shopify connection under <a href="{{ route('area-settings', [$brand, 'store']) }}" class="underline">Settings → Store</a>.
                @endif
            </p>
            @if ($missing > 0)
                <p class="mt-1 text-amber-700 dark:text-amber-400">{{ $missing }} {{ Str::plural('variant', $missing) }} {{ $missing === 1 ? 'has' : 'have' }} no packaging size yet. Without one a pack shot can't be scaled next to another.</p>
            @endif
        </div>
        @if ($canManage)
            <x-button variant="secondary" wire:click="syncNow" wire:loading.attr="disabled" :disabled="$running">{{ $running ? 'Reading the store…' : 'Read the store now' }}</x-button>
        @endif
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <x-form.input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name, type, tag, SKU or option" aria-label="Search products" class="max-w-sm" />
        <x-form.select wire:model.live="show" class="w-48" aria-label="Show">
            <option value="all">All products ({{ $total }})</option>
            <option value="live">On the store</option>
            <option value="missing-sizes">Missing packaging sizes</option>
            <option value="removed">Removed from the store</option>
        </x-form.select>
    </div>

    <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-800">
        <table class="w-full text-left">
            <thead class="bg-zinc-50 text-xs text-zinc-500 uppercase dark:bg-zinc-900 dark:text-zinc-400">
                <tr>
                    <th class="px-4 py-2 font-medium">Product</th>
                    <th class="px-4 py-2 font-medium">Variants</th>
                    <th class="px-4 py-2 font-medium">Sizes</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                @forelse ($products as $product)
                    @php($variants = $product->variants->whereNull('removed_at'))
                    @php($withSizes = $variants->filter->hasDimensions()->count())
                    <tr wire:key="product-{{ $product->id }}" class="align-top">
                        <td class="px-4 py-2.5">
                            <div class="flex items-start gap-3">
                                @if ($image = $product->images->whereNull('removed_at')->first())
                                    <img src="{{ $image->url }}" alt="" class="size-12 shrink-0 rounded border border-zinc-200 object-contain dark:border-zinc-700" loading="lazy" />
                                @else
                                    <span class="size-12 shrink-0 rounded bg-zinc-100 dark:bg-zinc-800"></span>
                                @endif
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $product->title }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $product->type }}@if ($product->type && $product->tags) · @endif{{ implode(', ', array_slice($product->tags, 0, 4)) }}
                                        @if ($product->removed_at) · <span class="text-red-600 dark:text-red-400">removed {{ $product->removed_at->diffForHumans() }}</span>
                                        @elseif (! $product->isLive()) · not on the store @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-400">
                            {{ $variants->count() }}
                            <div class="text-xs">{{ $variants->take(3)->map->label()->implode(', ') }}{{ $variants->count() > 3 ? '…' : '' }}</div>
                        </td>
                        <td class="px-4 py-2.5">
                            @if ($variants->isEmpty())
                                —
                            @elseif ($withSizes === $variants->count())
                                <span class="text-green-700 dark:text-green-400">All set</span>
                            @else
                                <span class="text-amber-700 dark:text-amber-400">{{ $variants->count() - $withSizes }} missing</span>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            @if ($open === $product->id)
                                <x-button size="sm" variant="ghost" wire:click="close">Close</x-button>
                            @else
                                <x-button size="sm" variant="ghost" wire:click="openProduct({{ $product->id }})">{{ $canManage ? 'Sizes' : 'Details' }}</x-button>
                            @endif
                        </td>
                    </tr>
                    @if ($open === $product->id)
                        <tr wire:key="product-open-{{ $product->id }}" class="bg-zinc-50 dark:bg-zinc-950/40">
                            <td colspan="4" class="px-4 py-4">
                                <div class="grid gap-6 lg:grid-cols-[1fr_16rem]">
                                    <form wire:submit="saveSizes" class="space-y-3">
                                        <p class="text-zinc-600 dark:text-zinc-400">Packaging size of each variant, in centimetres, as it stands on a shelf: width across the front, height, and depth front to back. Measured, not guessed.</p>
                                        <table class="w-full text-left">
                                            <thead class="text-xs text-zinc-500 uppercase dark:text-zinc-400">
                                                <tr><th class="py-1 pr-3 font-medium">Variant</th><th class="py-1 pr-3 font-medium">Width</th><th class="py-1 pr-3 font-medium">Height</th><th class="py-1 pr-3 font-medium">Depth</th><th></th></tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($variants as $variant)
                                                    <tr wire:key="variant-{{ $variant->id }}" class="align-top">
                                                        <td class="py-1.5 pr-3">
                                                            <div>{{ $variant->label() }}</div>
                                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $variant->sku }}{{ $variant->price !== null ? ' · $'.number_format((float) $variant->price, 2) : '' }}{{ $variant->available ? '' : ' · sold out' }}</div>
                                                        </td>
                                                        @foreach (['width', 'height', 'depth'] as $side)
                                                            <td class="py-1.5 pr-3">
                                                                <x-form.input type="number" step="0.1" min="0" wire:model="sizes.{{ $variant->id }}.{{ $side }}" class="w-24 py-1" :disabled="! $canManage" aria-label="{{ ucfirst($side) }} of {{ $variant->label() }} in cm" />
                                                                @error("sizes.{$variant->id}.{$side}") <p class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                                            </td>
                                                        @endforeach
                                                        <td class="py-1.5 text-right">
                                                            @if ($canManage && $variants->count() > 1)
                                                                <x-button size="sm" variant="ghost" type="button" wire:click="copyToSameSize({{ $variant->id }})" title="Give every variant of the same size these measurements">Copy to same size</x-button>
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        @if ($canManage)
                                            <x-button type="submit" size="sm">Save sizes</x-button>
                                        @endif
                                    </form>
                                    <div class="space-y-2">
                                        <p class="text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">Store photos</p>
                                        <div class="flex flex-wrap gap-2">
                                            @forelse ($product->images->whereNull('removed_at') as $image)
                                                <img src="{{ $image->url }}" alt="{{ $image->alt }}" class="size-16 rounded border border-zinc-200 bg-white object-contain dark:border-zinc-700" loading="lazy" />
                                            @empty
                                                <p class="text-zinc-500 dark:text-zinc-400">None on the store.</p>
                                            @endforelse
                                        </div>
                                        @if ($product->url)
                                            <a href="{{ $product->url }}" target="_blank" rel="noopener" class="text-xs underline">Open on the store</a>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                        @if ($search !== '' || $show !== 'all') Nothing matches. @else No products yet. @if ($store && $canManage) Press "Read the store now". @endif @endif
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $products->links() }}
</div>
