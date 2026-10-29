{{-- The page's one save button, floating at the bottom. Forms on the page report their unsaved changes to it (resources/js/save-bar.js). --}}
<div x-data="saveBar" wire:ignore x-cloak role="region" aria-label="Save changes"
     class="fixed bottom-4 left-1/2 z-40 flex -translate-x-1/2 items-center gap-3 rounded-lg border bg-white py-2 pr-2 pl-4 whitespace-nowrap shadow-lg transition sm:bottom-6 dark:bg-zinc-900"
     :class="total ? 'border-zinc-900 dark:border-white' : 'border-zinc-200 dark:border-zinc-700'">
    <span class="text-sm" :class="total ? 'font-medium' : 'text-zinc-500 dark:text-zinc-400'" aria-live="polite"
          x-text="total ? (total === 1 ? '1 unsaved change' : `${total} unsaved changes`) : 'No unsaved changes'"></span>
    <x-button variant="ghost" size="sm" x-show="total && ! saving" @click="discard()">Discard</x-button>
    <x-button x-bind:disabled="! total || saving" @click="save()">
        <span x-text="saving ? 'Saving…' : 'Save changes'">Save changes</span>
    </x-button>
</div>
