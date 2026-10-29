{{-- Applies the saved theme before first paint so dark mode doesn't flash,
     and again whenever Livewire swaps in a page (wire:navigate replaces the
     <html> attributes with the server's copy, which has no "dark" class). --}}
<script data-navigate-once>
    (() => {
        const apply = () => {
            const theme = localStorage.getItem('theme') ?? 'system';
            const dark = theme === 'dark' || (theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        };

        apply();
        // Before the new page is shown, so there's no light flash between pages.
        document.addEventListener('livewire:navigating', (e) => e.detail?.onSwap?.(apply));
        document.addEventListener('livewire:navigated', apply);
        // "System" follows the computer switching between light and dark.
        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', apply);
    })();
</script>
