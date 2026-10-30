<div class="space-y-8">
    <section id="kit-voice" class="scroll-mt-4">
        <div class="mb-1 flex items-baseline gap-2">
            <h3 class="font-semibold">Voice</h3>
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Required</span>
        </div>
        <p class="mb-3 max-w-3xl text-zinc-600 dark:text-zinc-400">Three to five sentences on how the brand sounds, then the words and phrases to use and the ones to avoid. Copy is written in the language the request asks for, so give French where you have it; English is used when French is empty.</p>
        <div class="space-y-5 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
            @foreach (['tone' => ['Tone', 'Confident and warm, never shouty. Talks to athletes as teammates…'], 'use' => ['Words and phrases to use', 'clean, fuel, recovery, game day'], 'avoid' => ['Words and phrases to avoid', 'cheap, miracle, guaranteed, #1']] as $part => [$label, $placeholder])
                <div>
                    <p class="font-medium">{{ $label }}</p>
                    <div class="mt-1.5 grid gap-3 sm:grid-cols-2">
                        @foreach (['en' => 'English', 'fr' => 'French'] as $lang => $language)
                            <label class="block text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $language }}
                                <x-form.textarea wire:model="voice.{{ $part }}.{{ $lang }}" class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100" :rows="$part === 'tone' ? 4 : 2" :placeholder="$lang === 'en' ? $placeholder : ''" :disabled="! $canManage" />
                            </label>
                            @error("voice.{$part}.{$lang}") <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section id="kit-direction" class="scroll-mt-4">
        <div class="mb-1 flex items-baseline gap-2">
            <h3 class="font-semibold">Visual direction</h3>
            <span class="text-xs text-zinc-500 dark:text-zinc-400">Required</span>
        </div>
        <p class="mb-3 max-w-3xl text-zinc-600 dark:text-zinc-400">How the brand's images look. The planner writes every image prompt from this, so be concrete: what to show, and what never to show.</p>
        <div class="grid gap-4 rounded-lg border border-zinc-200 bg-white p-5 sm:grid-cols-2 dark:border-zinc-800 dark:bg-zinc-900">
            @foreach (['mood' => ['Mood', 'Energetic, clean, optimistic'], 'lighting' => ['Lighting', 'Natural daylight, bright, no heavy shadows'], 'settings' => ['Settings', 'Gyms, rinks, kitchens after training, outdoors in Canada'], 'people' => ['People and activities', 'Real athletes of every age, mid-activity, never posed'], 'avoid' => ['What to avoid', 'Stock-photo smiles, neon, dark moody grading, text in the scene']] as $part => [$label, $placeholder])
                <x-form.field :label="$label" name="direction.{{ $part }}" @class(['sm:col-span-2' => $part === 'avoid'])>
                    <x-form.textarea wire:model="direction.{{ $part }}" id="direction.{{ $part }}" :placeholder="$placeholder" :rows="2" :disabled="! $canManage" />
                </x-form.field>
            @endforeach
        </div>
    </section>

    @if ($canManage)
        <div>
            <x-button wire:click="save" wire:loading.attr="disabled">Save voice and direction</x-button>
        </div>
    @endif
</div>
