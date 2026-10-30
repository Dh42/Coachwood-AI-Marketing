@php
    $itemFor = fn (string $key) => ! empty($rows[$key]['id']) ? $items->get((int) $rows[$key]['id']) : null;
    $checker = 'background-color:#fff;background-image:linear-gradient(45deg,#e4e4e7 25%,transparent 25%),linear-gradient(-45deg,#e4e4e7 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#e4e4e7 75%),linear-gradient(-45deg,transparent 75%,#e4e4e7 75%);background-size:16px 16px;background-position:0 0,0 8px,8px -8px,-8px 0';
    $needsTransparency = in_array($kind, [\App\BrandKit\Kind::Logo, \App\BrandKit\Kind::PackShot], true);
@endphp
<section id="kit-{{ $kind->value }}" class="scroll-mt-4">
    <div class="mb-1 flex items-baseline gap-2">
        <h3 class="font-semibold">{{ $kind->title() }}</h3>
        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $kind->need()->label() }}</span>
    </div>
    <p class="mb-3 max-w-3xl text-zinc-600 dark:text-zinc-400">{{ $kind->help() }}</p>

    <div class="space-y-4 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
        @error('uploads') <p class="text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

        @if ($gallery)
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($rows as $key => $row)
                    @php($item = $itemFor($key))
                    <div wire:key="row-{{ $key }}" class="flex flex-col gap-2 rounded-md border border-zinc-200 p-3 dark:border-zinc-700">
                        @if ($item?->isImage())
                            <a href="{{ $item->url() }}" target="_blank" rel="noopener" class="block overflow-hidden rounded" style="{{ $checker }}">
                                <img src="{{ $item->thumbUrl() }}" alt="{{ $item->original_name }}" class="mx-auto max-h-40 object-contain" loading="lazy" />
                            </a>
                            <p class="truncate text-xs text-zinc-500 dark:text-zinc-400" title="{{ $item->original_name }}">
                                {{ $item->original_name }}@if ($item->width && $item->height) · {{ $item->width }}×{{ $item->height }}@endif · {{ Illuminate\Support\Number::fileSize($item->size ?? 0) }}
                            </p>
                            @if ($needsTransparency && $item->isTransparent() === false)
                                <p class="text-xs text-amber-700 dark:text-amber-400">No transparent background: on a scene it would show as a box. Export it again with transparency.</p>
                            @endif
                        @endif
                        @foreach ($fields as $field)
                            @include('livewire.brand-kit._field', ['field' => $field, 'key' => $key, 'compact' => false])
                        @endforeach
                        @if ($canManage)
                            <div class="mt-auto pt-1 text-right">
                                @if ($removing === $key)
                                    <span class="text-xs text-zinc-600 dark:text-zinc-400">Remove this {{ $kind->one() }}?</span>
                                    <x-button size="sm" variant="danger" wire:click="removeRow('{{ $key }}')">Yes, remove</x-button>
                                    <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                                @else
                                    <x-button size="sm" variant="ghost" wire:click="askRemove('{{ $key }}')">Remove</x-button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                @if ($canManage && $spec)
                    <label class="flex min-h-32 cursor-pointer flex-col items-center justify-center gap-1 rounded-md border-2 border-dashed border-zinc-300 p-4 text-center text-zinc-600 hover:border-zinc-400 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:bg-zinc-800/60">
                        <input type="file" multiple accept="{{ $spec->accept() }}" wire:model="uploads" class="sr-only" aria-label="Add {{ strtolower($kind->title()) }}" />
                        <x-heroicon-o-arrow-up-tray class="size-5" />
                        <span class="font-medium" wire:loading.remove wire:target="uploads">Add {{ strtolower($kind->title()) }}</span>
                        <span class="font-medium" wire:loading wire:target="uploads">Uploading…</span>
                        <span class="text-xs">{{ $spec->describe() }}</span>
                    </label>
                @elseif ($rows === [])
                    <p class="text-zinc-500 dark:text-zinc-400">None yet.</p>
                @endif
            </div>
        @else
            @if ($rows !== [])
                <table class="w-full text-left">
                    <thead class="text-xs text-zinc-500 uppercase dark:text-zinc-400">
                        <tr>
                            @if ($spec) <th class="py-1 pr-3 font-medium">File</th> @endif
                            @foreach ($fields as $field)
                                <th class="py-1 pr-3 font-medium">{{ $field->label }}</th>
                            @endforeach
                            <th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($rows as $key => $row)
                            @php($item = $itemFor($key))
                            <tr wire:key="row-{{ $key }}" class="align-top">
                                @if ($spec)
                                    <td class="py-2 pr-3">
                                        @if ($item?->hasFile())
                                            @if ($spec->type === \App\BrandKit\FileSpec::AUDIO)
                                                <audio controls preload="none" src="{{ $item->url() }}" class="h-8 max-w-56"></audio>
                                            @endif
                                            <a href="{{ $item->url() }}" target="_blank" rel="noopener" class="block max-w-56 truncate text-xs underline" title="{{ $item->original_name }}">{{ $item->original_name }}</a>
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ Illuminate\Support\Number::fileSize($item->size ?? 0) }}</span>
                                        @endif
                                        @if ($canManage && ! $spec->required)
                                            @if ($item)
                                                <label class="block cursor-pointer text-xs text-zinc-600 underline dark:text-zinc-400">
                                                    <input type="file" accept="{{ $spec->accept() }}" wire:model="rowUploads.{{ $key }}" class="sr-only" aria-label="Font file for row {{ $key }}" />
                                                    <span wire:loading.remove wire:target="rowUploads.{{ $key }}">{{ $item->hasFile() ? 'Replace the file' : 'Upload the file' }}</span>
                                                    <span wire:loading wire:target="rowUploads.{{ $key }}">Uploading…</span>
                                                </label>
                                            @else
                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">Save first, then upload its file.</span>
                                            @endif
                                            @error("rowUploads.{$key}") <p class="mt-0.5 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                        @endif
                                    </td>
                                @endif
                                @foreach ($fields as $field)
                                    <td class="py-2 pr-3">
                                        @include('livewire.brand-kit._field', ['field' => $field, 'key' => $key, 'compact' => true])
                                    </td>
                                @endforeach
                                <td class="py-2 text-right whitespace-nowrap">
                                    @if ($canManage)
                                        @if ($removing === $key)
                                            <x-button size="sm" variant="danger" wire:click="removeRow('{{ $key }}')">Yes, remove</x-button>
                                            <x-button size="sm" variant="ghost" wire:click="keep">Keep</x-button>
                                        @else
                                            <x-button size="sm" variant="ghost" wire:click="askRemove('{{ $key }}')">Remove</x-button>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-zinc-500 dark:text-zinc-400">None yet.</p>
            @endif

            @if ($canManage)
                <div class="flex flex-wrap items-center gap-2">
                    @if (! $kind->needsFile())
                        <x-button size="sm" variant="secondary" wire:click="add">Add {{ $kind->one() }}</x-button>
                    @endif
                    @if ($spec?->required)
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs font-medium hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700">
                            <input type="file" multiple accept="{{ $spec->accept() }}" wire:model="uploads" class="sr-only" aria-label="Add {{ strtolower($kind->title()) }}" />
                            <x-heroicon-o-arrow-up-tray class="size-4" />
                            <span wire:loading.remove wire:target="uploads">Add {{ $kind->one() }}s ({{ $spec->describe() }})</span>
                            <span wire:loading wire:target="uploads">Uploading…</span>
                        </label>
                    @endif
                </div>
            @endif
        @endif

        @if ($canManage && $rows !== [])
            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-800">
                <x-button size="sm" wire:click="save" wire:loading.attr="disabled">Save {{ strtolower($kind->title()) }}</x-button>
            </div>
        @endif
    </div>
</section>
