@props(['item', 'brand', 'indent' => false])

<a href="{{ $item->url($brand) }}"
   @class([
       'flex items-center gap-2.5 rounded-md px-2 py-1.5 font-medium',
       'pl-4' => $indent,
       'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white' => $item->isActive(),
       'text-zinc-600 hover:bg-zinc-50 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800/60 dark:hover:text-white' => ! $item->isActive(),
   ])
   @if ($item->isActive()) aria-current="page" @endif>
    <x-dynamic-component :component="$item->icon" class="size-5 shrink-0" />
    {{ $item->label }}
    @if ($count = $item->badgeFor(auth()->user(), $brand))
        <span class="ml-auto rounded-full bg-red-600 px-1.5 py-0.5 text-xs leading-none text-white" aria-label="{{ $count }} open">{{ $count }}</span>
    @endif
</a>
