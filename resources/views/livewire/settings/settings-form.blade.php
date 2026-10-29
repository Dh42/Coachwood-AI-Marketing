<div class="max-w-3xl space-y-8"
     @if ($batch && ! $readOnly) x-data="settingsBatch(@js($this->getId()))" @endif>
    @if ($readOnly)
        <p class="rounded-md bg-zinc-100 px-3 py-2 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">You can see these settings, but your role can't change them.</p>
    @endif

    @foreach ($grouped as $section => $rows)
        <section>
            @if ($titles)
                {{-- "Help centre: search" reads "Search" here: the page already says which feature it is. --}}
                <h2 class="mb-2 font-semibold">{{ \Illuminate\Support\Str::ucfirst(\Illuminate\Support\Str::after($section, ': ')) }}</h2>
            @endif

            <div class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                @foreach ($rows->reject(fn ($row) => $row['resolved']->definition->advanced) as ['field' => $field, 'resolved' => $resolved, 'platform' => $platform])
                    @include('livewire.settings.setting-row')
                @endforeach

                @if (($advanced = $rows->filter(fn ($row) => $row['resolved']->definition->advanced))->isNotEmpty())
                    {{-- wire:ignore.self: stays open or closed as the person left it when the form redraws. --}}
                    <details class="group/advanced" wire:ignore.self>
                        <summary class="flex cursor-pointer list-none items-center gap-1.5 px-5 py-3 text-zinc-500 select-none hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                            <x-heroicon-m-chevron-right class="size-4 transition group-open/advanced:rotate-90" />
                            Advanced <span class="text-xs">({{ $advanced->count() }})</span>
                        </summary>
                        <div class="divide-y divide-zinc-200 border-t border-zinc-200 dark:divide-zinc-800 dark:border-zinc-800">
                            @foreach ($advanced as ['field' => $field, 'resolved' => $resolved, 'platform' => $platform])
                                @include('livewire.settings.setting-row')
                            @endforeach
                        </div>
                    </details>
                @endif
            </div>
        </section>
    @endforeach
</div>
