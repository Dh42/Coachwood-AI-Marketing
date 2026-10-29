@props(['variant' => 'primary', 'size' => 'md'])

<button {{ $attributes->merge(['type' => 'button'])->class([
    'inline-flex items-center justify-center gap-1.5 rounded-md font-medium disabled:cursor-not-allowed disabled:opacity-50',
    'px-3 py-2' => $size === 'md',
    'px-2 py-1 text-xs' => $size === 'sm',
    'bg-zinc-900 text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200' => $variant === 'primary',
    'border border-zinc-300 bg-white hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700' => $variant === 'secondary',
    'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white' => $variant === 'ghost',
    'bg-red-600 text-white hover:bg-red-500' => $variant === 'danger',
]) }}>
    {{ $slot }}
</button>
