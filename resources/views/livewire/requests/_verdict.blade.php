{{-- The reviewer's score and one-line reason under an output. Expects $output. --}}
@if ($output->score !== null)
    <figcaption class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400" title="{{ $output->review?->scores ? collect($output->review->scores)->map(fn ($s) => $s['label'].' '.$s['score'])->implode(', ') : '' }}">
        <span @class(['font-medium', 'text-green-700 dark:text-green-400' => $output->score >= 70, 'text-amber-700 dark:text-amber-400' => $output->score < 70])>{{ $output->score }}</span>
        @if ($output->isShortlisted()) · <span class="text-green-700 dark:text-green-400">Shortlisted</span> @endif
        @if ($output->reason) · {{ $output->reason }} @endif
    </figcaption>
@elseif ($request->status === 'running')
    <figcaption class="mt-0.5 text-xs text-zinc-400">Reviewing…</figcaption>
@elseif ($output->review && $output->review->status === 'failed')
    <figcaption class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">Not reviewed: {{ $output->review->error }}</figcaption>
@endif
