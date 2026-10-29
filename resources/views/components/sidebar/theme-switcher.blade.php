<div x-data="{
        theme: localStorage.getItem('theme') ?? 'system',
        set(theme) {
            this.theme = theme;
            localStorage.setItem('theme', theme);
            const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        },
    }"
     class="flex rounded-md bg-zinc-100 p-0.5 dark:bg-zinc-800" role="radiogroup" aria-label="Theme">
    @foreach (['light' => ['Light', 'heroicon-m-sun'], 'dark' => ['Dark', 'heroicon-m-moon'], 'system' => ['System', 'heroicon-m-computer-desktop']] as $value => [$label, $icon])
        <button type="button" role="radio" @click="set('{{ $value }}')" :aria-checked="theme === '{{ $value }}'"
                :class="theme === '{{ $value }}' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                class="flex flex-1 items-center justify-center gap-1.5 rounded px-2 py-1 text-xs font-medium">
            <x-dynamic-component :component="$icon" class="size-4" />
            {{ $label }}
        </button>
    @endforeach
</div>
