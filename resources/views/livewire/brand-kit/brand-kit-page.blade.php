<div class="max-w-5xl space-y-10">
    <div class="space-y-4">
        <p class="max-w-3xl text-zinc-600 dark:text-zinc-400">
            Everything the app needs to know about {{ $brand->name }} before it plans, generates and reviews: the logos and colours it puts on finished ads, the fonts it typesets with, how the brand sounds, how it looks, and examples of right and wrong.
            The starting prompts are built from this kit.
        </p>

        <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 class="font-semibold">What the kit needs</h2>
                @if ($ready)
                    <span class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800 dark:bg-green-950/60 dark:text-green-300">Ready to generate</span>
                @else
                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">Not ready yet: the required items below are missing</span>
                @endif
            </div>
            <ul class="grid gap-x-6 sm:grid-cols-2">
                @foreach ($checks as $check)
                    <li class="flex items-start gap-2.5 px-4 py-2">
                        @if ($check['ok'])
                            <x-heroicon-s-check-circle class="mt-0.5 size-4 shrink-0 text-green-600 dark:text-green-400" />
                        @elseif ($check['need'] === \App\BrandKit\Need::Required)
                            <x-heroicon-s-exclamation-circle class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                        @else
                            <span class="mt-1 ml-0.5 size-3 shrink-0 rounded-full border-2 border-zinc-300 dark:border-zinc-600"></span>
                        @endif
                        <div class="min-w-0">
                            <a href="#kit-{{ $check['key'] }}" class="font-medium hover:underline">{{ $check['title'] }}</a>
                            <span class="text-xs text-zinc-500 dark:text-zinc-400">· {{ $check['need']->label() }}</span>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $check['detail'] }}</div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="space-y-10">
        <div class="space-y-8">
            <h2 class="text-xs font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">Identity</h2>
            @foreach ($groups['Identity'] as $kind)
                <livewire:brand-kit.kit-items :brand="$brand" :kind="$kind->value" :can-manage="$canManage" :key="'kit-'.$kind->value" />
            @endforeach
        </div>

        <div class="space-y-8">
            <h2 class="text-xs font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">Voice and look</h2>
            <livewire:brand-kit.kit-text :brand="$brand" :can-manage="$canManage" key="kit-text" />
        </div>

        @foreach (['Examples', 'Words', 'Media'] as $group)
            <div class="space-y-8">
                <h2 class="text-xs font-medium tracking-wide text-zinc-400 uppercase dark:text-zinc-500">{{ $group }}</h2>
                @foreach ($groups[$group] as $kind)
                    <livewire:brand-kit.kit-items :brand="$brand" :kind="$kind->value" :can-manage="$canManage" :key="'kit-'.$kind->value" />
                @endforeach
            </div>
        @endforeach
    </div>
</div>
