<x-layouts::app title="Brands">
    <div class="mx-auto mt-16 max-w-md text-center">
        <x-heroicon-o-squares-plus class="mx-auto size-8 text-zinc-400" />
        <h2 class="mt-3 text-base font-semibold">No brands yet</h2>
        <p class="mt-2 text-zinc-600 dark:text-zinc-400">
            A brand is one store's marketing: its catalogue, brand kit, prompts, templates and settings.
        </p>
        @can('brands.create')
            <a href="{{ route('brands.create') }}" class="mt-5 inline-flex items-center gap-1.5 rounded-md bg-zinc-900 px-3 py-2 font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                <x-heroicon-m-plus class="size-4" />
                New brand
            </a>
        @else
            <p class="mt-2 text-zinc-600 dark:text-zinc-400">Ask an admin to create one.</p>
        @endcan
    </div>
</x-layouts::app>
