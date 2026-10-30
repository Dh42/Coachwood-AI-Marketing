{{-- A rejected output's thumbnail, or a note once the nightly clean-up has deleted its file. Expects $output and $rejectsDays. --}}
@if ($output->isRemoved())
    <div class="flex aspect-square items-center justify-center rounded-md border border-dashed border-zinc-300 px-2 text-center text-xs text-zinc-400 dark:border-zinc-700">File deleted after {{ $rejectsDays === 1 ? 'a day' : "{$rejectsDays} days" }}</div>
@else
    <a href="{{ $output->url() }}" target="_blank" class="block overflow-hidden rounded-md bg-zinc-100 opacity-70 dark:bg-zinc-800"><img src="{{ $output->thumbUrl() }}" alt="" class="w-full object-contain" loading="lazy" /></a>
@endif
