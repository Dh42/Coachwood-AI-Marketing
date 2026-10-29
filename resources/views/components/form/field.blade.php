@props(['label', 'name', 'help' => null])

<div {{ $attributes }}>
    <label for="{{ $name }}" class="block font-medium">{{ $label }}</label>
    @if ($help)
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $help }}</p>
    @endif
    <div class="mt-1.5">{{ $slot }}</div>
    @error($name)
        <p data-save-error class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p>
    @enderror
</div>
