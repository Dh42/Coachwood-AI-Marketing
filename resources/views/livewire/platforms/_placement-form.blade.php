@php($takesImages = in_array(\App\Models\Placement::IMAGE, $placementForm['formats'] ?? [], true))
@php($takesVideo = in_array(\App\Models\Placement::VIDEO, $placementForm['formats'] ?? [], true))
<form wire:submit="savePlacement" class="space-y-5">
    <div class="grid gap-4 sm:grid-cols-[1fr_8rem_8rem]">
        <x-form.field label="Name" name="placementForm.name">
            <x-form.input wire:model="placementForm.name" id="placementForm.name" placeholder="Feed (4:5)" />
        </x-form.field>
        <x-form.field label="Width (px)" name="placementForm.width">
            <x-form.input type="number" min="100" max="8000" wire:model="placementForm.width" id="placementForm.width" />
        </x-form.field>
        <x-form.field label="Height (px)" name="placementForm.height">
            <x-form.input type="number" min="100" max="8000" wire:model="placementForm.height" id="placementForm.height" />
        </x-form.field>
    </div>

    <div>
        <p class="font-medium">Takes</p>
        <div class="mt-1.5 flex flex-wrap gap-4">
            @foreach ([\App\Models\Placement::IMAGE => 'Images', \App\Models\Placement::VIDEO => 'Video'] as $format => $label)
                <label class="inline-flex items-center gap-2"><input type="checkbox" value="{{ $format }}" wire:model.live="placementForm.formats" class="size-4 rounded border-zinc-300 dark:border-zinc-600" /> {{ $label }}</label>
            @endforeach
        </div>
        @error('placementForm.formats') <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    <div>
        <p class="font-medium">Safe zone</p>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">Pixels from each edge that the platform's own captions and buttons cover. Nothing important is placed there, and the review fails a finished ad that puts text there.</p>
        <div class="mt-1.5 grid max-w-xl grid-cols-4 gap-3">
            @foreach (['safe_top' => 'Top', 'safe_bottom' => 'Bottom', 'safe_left' => 'Left', 'safe_right' => 'Right'] as $side => $label)
                <label class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}
                    <x-form.input type="number" min="0" wire:model="placementForm.{{ $side }}" class="mt-0.5 py-1 text-sm text-zinc-900 dark:text-zinc-100" />
                </label>
            @endforeach
        </div>
        @error('placementForm.safe_top') <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    </div>

    @if ($takesImages)
        <div class="grid gap-4 sm:grid-cols-[1fr_12rem]">
            <div>
                <p class="font-medium">Image files</p>
                <div class="mt-1.5 flex flex-wrap gap-4">
                    @foreach (\App\Models\Placement::IMAGE_TYPES as $type)
                        <label class="inline-flex items-center gap-2"><input type="checkbox" value="{{ $type }}" wire:model="placementForm.image_types" class="size-4 rounded border-zinc-300 dark:border-zinc-600" /> {{ strtoupper($type) }}</label>
                    @endforeach
                </div>
                @error('placementForm.image_types') <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <x-form.field label="Largest image (KB)" name="placementForm.max_image_kb">
                <x-form.input type="number" min="1" wire:model="placementForm.max_image_kb" id="placementForm.max_image_kb" placeholder="No limit" />
            </x-form.field>
        </div>
    @endif

    @if ($takesVideo)
        <div class="grid gap-4 sm:grid-cols-[1fr_10rem_8rem_8rem]">
            <div>
                <p class="font-medium">Video files</p>
                <div class="mt-1.5 flex flex-wrap gap-4">
                    @foreach (\App\Models\Placement::VIDEO_TYPES as $type)
                        <label class="inline-flex items-center gap-2"><input type="checkbox" value="{{ $type }}" wire:model="placementForm.video_types" class="size-4 rounded border-zinc-300 dark:border-zinc-600" /> {{ strtoupper($type) }}</label>
                    @endforeach
                </div>
                @error('placementForm.video_types') <p class="mt-1 text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>
            <x-form.field label="Largest video (MB)" name="placementForm.max_video_mb">
                <x-form.input type="number" min="1" wire:model="placementForm.max_video_mb" id="placementForm.max_video_mb" placeholder="No limit" />
            </x-form.field>
            <x-form.field label="Shortest (s)" name="placementForm.video_min_seconds">
                <x-form.input type="number" min="1" wire:model="placementForm.video_min_seconds" id="placementForm.video_min_seconds" />
            </x-form.field>
            <x-form.field label="Longest (s)" name="placementForm.video_max_seconds">
                <x-form.input type="number" min="1" wire:model="placementForm.video_max_seconds" id="placementForm.video_max_seconds" />
            </x-form.field>
        </div>
    @endif

    <div>
        <p class="font-medium">Copy</p>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">How long each piece may be, in characters (hashtags: how many). Leave a piece empty if the placement doesn't take it.</p>
        <div class="mt-1.5 grid max-w-3xl gap-3 sm:grid-cols-5">
            @foreach ($copyFields as $field)
                <label class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $field->label() }}
                    <x-form.input type="number" min="1" wire:model="placementForm.copy_limits.{{ $field->value }}" class="mt-0.5 py-1 text-sm text-zinc-900 dark:text-zinc-100" placeholder="—" />
                    @error("placementForm.copy_limits.{$field->value}") <span class="text-red-600 dark:text-red-400">{{ $message }}</span> @enderror
                </label>
            @endforeach
        </div>
    </div>

    <x-form.field label="Notes" name="placementForm.notes" help="Anything the team or the planner should know about this placement.">
        <x-form.textarea wire:model="placementForm.notes" id="placementForm.notes" rows="2" />
    </x-form.field>

    <div class="flex gap-2">
        <x-button type="submit" size="sm" wire:loading.attr="disabled">Save placement</x-button>
        <x-button type="button" size="sm" variant="ghost" wire:click="closeAll">Cancel</x-button>
    </div>
</form>
