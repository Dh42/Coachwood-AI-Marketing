{{-- Quick find (Ctrl+K / ⌘K): jump to any page or setting by name. Entries from App\Navigation\QuickFind. --}}
@php($entries = app(\App\Navigation\QuickFind::class)->entries(auth()->user(), app(\App\Brands\CurrentBrand::class)->get()))
<div x-data="quickFind" @keydown.window="shortcut($event)">
    <button type="button" @click="show()" data-quick-find
            class="inline-flex items-center gap-2 rounded-md border border-zinc-300 px-2.5 py-1 text-xs text-zinc-500 hover:bg-zinc-50 hover:text-zinc-900 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white">
        <x-heroicon-m-magnifying-glass class="size-4" /> Find a page or setting
        <kbd class="rounded border border-zinc-300 px-1 font-sans text-[10px] dark:border-zinc-600" x-text="mac ? '⌘K' : 'Ctrl K'">Ctrl K</kbd>
    </button>

    <template x-teleport="body">
        <div x-cloak x-show="open" x-transition.opacity class="fixed inset-0 z-50 flex items-start justify-center bg-zinc-900/40 p-4 pt-[12vh]" @click.self="open = false" @keydown.escape="open = false">
            <div class="w-full max-w-xl overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900" role="dialog" aria-label="Find a page or setting">
                <div class="flex items-center gap-2 border-b border-zinc-200 px-4 dark:border-zinc-700">
                    <x-heroicon-m-magnifying-glass class="size-4 text-zinc-400" />
                    <input x-ref="input" x-model="query" @input="active = 0" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="go(results[active])"
                           type="text" placeholder="Escalation tags, SMS, weekly audit…" aria-label="Search"
                           class="w-full bg-transparent py-3 text-sm outline-none placeholder:text-zinc-400">
                </div>
                <ul class="max-h-[55vh] overflow-y-auto py-1" role="listbox">
                    <template x-for="(entry, index) in results" :key="entry.url + entry.title">
                        <li role="option" :aria-selected="index === active">
                            <a :href="entry.url" @mouseenter="active = index" @click.prevent="go(entry)"
                               class="flex items-baseline justify-between gap-3 px-4 py-2" :class="index === active ? 'bg-zinc-100 dark:bg-zinc-800' : ''">
                                <span class="font-medium" x-text="entry.title"></span>
                                <span class="truncate text-xs text-zinc-500 dark:text-zinc-400" x-text="entry.where"></span>
                            </a>
                        </li>
                    </template>
                    <li x-show="query && ! results.length" class="px-4 py-3 text-zinc-500 dark:text-zinc-400">Nothing matches.</li>
                </ul>
            </div>
        </div>
    </template>
    <script type="application/json" data-quick-find-entries>@json($entries)</script>
</div>
