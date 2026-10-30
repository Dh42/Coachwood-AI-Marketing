<?php

namespace App\Platforms;

/**
 * The aspect ratio a placement is generated at. Sizes within 2% of a common
 * ratio share it (1200 × 627 and 1200 × 628 are both 1.91:1), which is what
 * lets one generation serve every placement of that shape.
 */
final class AspectRatio
{
    /** Common ratios, by width over height. */
    private const COMMON = [
        '1:1' => 1.0,
        '4:5' => 0.8,
        '9:16' => 0.5625,
        '16:9' => 1.7778,
        '1.91:1' => 1.91,
        '2:3' => 0.6667,
        '3:2' => 1.5,
        '4:3' => 1.3333,
        '3:4' => 0.75,
        '2:1' => 2.0,
        '1:2' => 0.5,
        '5:4' => 1.25,
        '21:9' => 2.3333,
    ];

    public static function of(int $width, int $height): string
    {
        if ($width < 1 || $height < 1) {
            return '1:1';
        }

        $value = $width / $height;
        foreach (self::COMMON as $label => $ratio) {
            if (abs($value - $ratio) / $ratio <= 0.02) {
                return $label;
            }
        }

        $gcd = self::gcd($width, $height);

        return ($width / $gcd).':'.($height / $gcd);
    }

    /**
     * Width over height for a label such as "4:5" or "1.91:1".
     */
    public static function value(string $label): float
    {
        [$w, $h] = array_map('floatval', explode(':', $label) + [1, 1]);

        return $h > 0 ? $w / $h : 1.0;
    }

    public static function isPortrait(string $label): bool
    {
        return self::value($label) < 0.98;
    }

    public static function isLandscape(string $label): bool
    {
        return self::value($label) > 1.02;
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }
}
