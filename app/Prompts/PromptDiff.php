<?php

namespace App\Prompts;

/**
 * A line-by-line comparison of two prompt texts, laid out side by side:
 * unchanged lines on both sides, removed lines on the left, added lines
 * on the right, with a changed run paired up row by row.
 */
class PromptDiff
{
    /** Longer texts are compared line by line only up to this many lines. */
    private const MAX_LINES = 2000;

    /**
     * @return list<array{kind: 'same'|'removed'|'added'|'changed', left: ?string, right: ?string}>
     */
    public static function sideBySide(string $old, string $new): array
    {
        $a = array_slice(preg_split('/\R/', rtrim($old)), 0, self::MAX_LINES);
        $b = array_slice(preg_split('/\R/', rtrim($new)), 0, self::MAX_LINES);
        $rows = [];
        $removed = [];
        $added = [];

        $flush = function () use (&$rows, &$removed, &$added) {
            $n = max(count($removed), count($added));
            for ($i = 0; $i < $n; $i++) {
                $l = $removed[$i] ?? null;
                $r = $added[$i] ?? null;
                $rows[] = ['kind' => $l !== null && $r !== null ? 'changed' : ($l !== null ? 'removed' : 'added'), 'left' => $l, 'right' => $r];
            }
            $removed = $added = [];
        };

        foreach (self::ops($a, $b) as [$op, $line]) {
            match ($op) {
                '-' => $removed[] = $line,
                '+' => $added[] = $line,
                default => (function () use (&$rows, $line, $flush) {
                    $flush();
                    $rows[] = ['kind' => 'same', 'left' => $line, 'right' => $line];
                })(),
            };
        }
        $flush();

        return $rows;
    }

    public static function changed(string $old, string $new): bool
    {
        return rtrim($old) !== rtrim($new);
    }

    /**
     * The edit script from a longest common subsequence of lines.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: '='|'-'|'+', 1: string}>
     */
    private static function ops(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $ops = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $ops[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $ops[] = ['-', $a[$i++]];
            } else {
                $ops[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $b[$j++]];
        }

        return $ops;
    }
}
