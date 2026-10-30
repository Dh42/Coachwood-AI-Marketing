{{-- Two revisions side by side. Expects $rows from PromptDiff::sideBySide, and $leftTitle / $rightTitle. --}}
<div class="overflow-x-auto rounded-md border border-zinc-200 dark:border-zinc-800">
    <table class="w-full table-fixed text-xs">
        <thead class="bg-zinc-50 text-zinc-500 dark:bg-zinc-950/40 dark:text-zinc-400">
            <tr><th class="w-1/2 px-3 py-1.5 text-left font-medium">{{ $leftTitle }}</th><th class="w-1/2 px-3 py-1.5 text-left font-medium">{{ $rightTitle }}</th></tr>
        </thead>
        <tbody class="divide-y divide-zinc-100 font-mono dark:divide-zinc-800">
            @foreach ($rows as $row)
                <tr class="align-top">
                    <td @class(['px-3 py-0.5 break-words whitespace-pre-wrap', 'bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-200' => in_array($row['kind'], ['removed', 'changed']), 'bg-zinc-50/60 dark:bg-zinc-950/20' => $row['kind'] === 'added'])>{{ $row['left'] ?? '' }}</td>
                    <td @class(['px-3 py-0.5 break-words whitespace-pre-wrap', 'bg-green-50 text-green-900 dark:bg-green-950/40 dark:text-green-200' => in_array($row['kind'], ['added', 'changed']), 'bg-zinc-50/60 dark:bg-zinc-950/20' => $row['kind'] === 'removed'])>{{ $row['right'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
