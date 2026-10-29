<div @class(['pb-20' => $canManage])>
    @if ($canManage)
        <x-save-bar />
    @endif

    @if (session('status'))
        <p class="mb-6 max-w-3xl rounded-md bg-green-50 px-3 py-2 text-green-800 dark:bg-green-950/50 dark:text-green-300">{{ session('status') }}</p>
    @endif

    <x-settings-hub :brand="$brand" current="brand">
        <div class="max-w-3xl space-y-8">
            <section x-data="wireSource(@js($this->getId().'-general'), ['name', 'color'], 'savedGeneral', 'saveGeneral', 'discardGeneral')">
                <h2 class="mb-2 font-semibold">Brand</h2>
                <div class="space-y-4 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <x-form.field label="Name" name="name">
                        <x-form.input wire:model="name" id="name" :disabled="! $canManage" />
                    </x-form.field>
                    <x-form.field label="Colour" name="color" help="The dot beside the name in the switcher.">
                        <input type="color" wire:model="color" id="color" @disabled(! $canManage) class="h-9 w-16 cursor-pointer rounded-md border border-zinc-300 bg-white p-0.5 dark:border-zinc-700 dark:bg-zinc-800" />
                    </x-form.field>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">ID: <code>{{ $brand->slug }}</code>. It's in every address for this brand and can't change.</p>
                </div>
            </section>

            <livewire:settings.settings-form :scope="\App\Settings\SettingScope::Brand" :scope-id="$brand->id" ability="brands.manage" :except="$homed" :batch="true" :key="'brand-settings-'.$brand->id" />
        </div>
    </x-settings-hub>
</div>
