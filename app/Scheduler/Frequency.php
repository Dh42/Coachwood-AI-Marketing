<?php

namespace App\Scheduler;

use Illuminate\Support\Carbon;

/**
 * A cron expression in plain words, for the shapes the schedule uses. Times
 * are shown in the platform's time zone. Anything else shows as it is.
 */
final class Frequency
{
    /**
     * Roughly how many minutes between runs, for ordering: the most frequent
     * first. Shapes it can't read sort last.
     */
    public static function minutes(string $expression): int
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            return PHP_INT_MAX;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        return match (true) {
            $day !== '*' || $month !== '*' => 43200,
            $weekday !== '*' => 10080,
            $hour !== '*' => 1440,
            $minute === '*' => 1,
            (bool) preg_match('/^\*\/(\d+)$/', $minute, $m) => (int) $m[1],
            ctype_digit($minute) => 60,
            default => PHP_INT_MAX,
        };
    }

    public static function describe(string $expression, string $cronTz, string $showTz): string
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            return $expression;
        }

        [$minute, $hour, $day, $month, $weekday] = $parts;

        if ($day !== '*' || $month !== '*') {
            return $expression;
        }

        if ($hour === '*' && $weekday === '*') {
            if ($minute === '*') {
                return 'Every minute';
            }
            if (preg_match('/^\*\/(\d+)$/', $minute, $m)) {
                return "Every {$m[1]} minutes";
            }
            if (ctype_digit($minute)) {
                return $minute === '0' ? 'Every hour' : "Every hour, at {$minute} minutes past";
            }

            return $expression;
        }

        if (! ctype_digit($minute) || ! ctype_digit($hour)) {
            return $expression;
        }

        $at = Carbon::today($cronTz)->setTime((int) $hour, (int) $minute)->timezone($showTz)->format('g:i A');

        if ($weekday === '*') {
            return "Daily at {$at}";
        }

        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return ctype_digit($weekday) && isset($days[(int) $weekday]) ? "{$days[(int) $weekday]}s at {$at}" : $expression;
    }
}
