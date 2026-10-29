@props(['connection'])

@php
    [$label, $classes] = match ($connection->status) {
        \App\Models\Connection::STATUS_CONNECTED => ['Connected', 'bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-300'],
        \App\Models\Connection::STATUS_ERROR => ['Problem', 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300'],
        \App\Models\Connection::STATUS_NEEDS_RECONNECT => ['Needs reconnect', 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300'],
        default => ['Not signed in', 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300'],
    };
@endphp

<span {{ $attributes->class("inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium {$classes}") }}>
    <span class="size-1.5 rounded-full bg-current"></span>
    {{ $label }}
</span>
