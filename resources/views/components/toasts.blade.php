{{-- Short confirmations and errors. Livewire components send them with
     $this->dispatch('saved', message: '...') or dispatch('failed', ...). --}}
<div x-data="{
        toasts: [],
        add(message, failed) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, failed });
            setTimeout(() => this.toasts = this.toasts.filter(t => t.id !== id), failed ? 8000 : 4000);
        },
    }"
     @saved.window="add($event.detail.message, false)"
     @failed.window="add($event.detail.message, true)"
     class="pointer-events-none fixed right-4 bottom-4 z-50 flex w-80 flex-col gap-2" aria-live="polite">
    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition.opacity
             :class="toast.failed ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200' : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900'"
             class="pointer-events-auto rounded-md border px-4 py-3 shadow-lg" x-text="toast.message" :role="toast.failed ? 'alert' : 'status'"></div>
    </template>
</div>
