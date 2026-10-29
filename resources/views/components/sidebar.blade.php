@php
    $nav = app(\App\Navigation\Navigation::class);
    $brand = app(\App\Brands\CurrentBrand::class)->get();
    $brands = auth()->user()?->visibleBrands() ?? collect();
    $trees = collect(\App\Navigation\NavSection::cases())->mapWithKeys(fn ($s) => [$s->value => $nav->tree($s, auth()->user(), $brand)]);
    // The heading holding the current page starts open.
    $activeHeading = $trees->flatten(1)->first(fn ($e) => $e['heading'] && $e['items']->contains(fn ($i) => $i->isActive()))['heading']->key ?? null;
@endphp

<nav class="flex w-64 shrink-0 flex-col border-r border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
    @if ($brands->isNotEmpty() || auth()->user()?->can('brands.create'))
        <div class="border-b border-zinc-200 p-3 dark:border-zinc-800">
            <x-sidebar.brand-switcher :current="$brand" :brands="$brands" />
        </div>
    @endif

    {{-- One heading open at a time: the one holding the current page, else the one this browser last opened. --}}
    <div class="flex-1 space-y-6 overflow-y-auto p-3"
         x-data="{ openHeading: @js($activeHeading) ?? (() => { try { return localStorage.getItem('nav-open') } catch (e) { return null } })(),
                   toggle(key) { this.openHeading = this.openHeading === key ? null : key; try { localStorage.setItem('nav-open', this.openHeading ?? '') } catch (e) {} } }">
        @foreach (\App\Navigation\NavSection::cases() as $section)
            @php($entries = $trees[$section->value])

            @if ($entries->isNotEmpty())
                <div>
                    <p class="px-2 pb-1 text-xs font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                        {{ $section === \App\Navigation\NavSection::Brand ? $brand->name : $section->label() }}
                    </p>

                    <ul class="space-y-0.5">
                        @foreach ($entries as $entry)
                            @if (! $entry['heading'])
                                <li><x-sidebar.nav-link :item="$entry['items'][0]" :brand="$brand" /></li>
                            @else
                                @php($heading = $entry['heading'])
                                @php($active = $entry['items']->contains(fn ($i) => $i->isActive()))
                                @php($total = $entry['items']->sum(fn ($i) => $i->badgeFor(auth()->user(), $brand)))
                                <li x-data="{ get open() { return this.openHeading === @js($heading->key) } }">
                                    <button type="button" :aria-expanded="open.toString()" @click="toggle(@js($heading->key))"
                                            class="flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left font-medium text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white">
                                        <x-dynamic-component :component="$heading->icon" class="size-5 shrink-0" />
                                        {{ $heading->label }}
                                        <span class="ml-auto flex items-center gap-1.5">
                                            @if ($total)
                                                <span x-show="! open" class="rounded-full bg-red-600 px-1.5 py-0.5 text-xs leading-none text-white" aria-label="{{ $total }} open">{{ $total }}</span>
                                            @endif
                                            <x-heroicon-m-chevron-right class="size-4 shrink-0 transition-transform" x-bind:class="open && 'rotate-90'" />
                                        </span>
                                    </button>
                                    <ul x-show="open" @unless ($active) x-cloak @endunless class="mt-0.5 space-y-0.5">
                                        @foreach ($entry['items'] as $item)
                                            <li><x-sidebar.nav-link :item="$item" :brand="$brand" indent /></li>
                                        @endforeach
                                    </ul>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif
        @endforeach
    </div>

    <div class="space-y-2 border-t border-zinc-200 p-3 dark:border-zinc-800">
        <x-sidebar.user-menu />
        <x-sidebar.theme-switcher />
    </div>
</nav>
