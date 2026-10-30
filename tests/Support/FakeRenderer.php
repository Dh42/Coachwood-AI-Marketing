<?php

namespace Tests\Support;

use App\Composer\Renderer;

/**
 * Stands in for Chrome: a flat PNG of the size asked for, keeping the
 * HTML it was given so tests can read it.
 */
class FakeRenderer implements Renderer
{
    /** @var list<array{html: string, width: int, height: int, assets: list<string>}> */
    public array $renders = [];

    public function __construct(public ?int $forceWidth = null) {}

    public function render(string $html, int $width, int $height, string $directory): string
    {
        $this->renders[] = ['html' => $html, 'width' => $width, 'height' => $height, 'assets' => array_values(array_diff(scandir($directory), ['.', '..']))];

        $image = imagecreatetruecolor($this->forceWidth ?? $width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 80, 120));
        // Some texture, so JPEG sizes vary with quality.
        for ($i = 0; $i < 200; $i++) {
            imagefilledellipse($image, random_int(0, $width), random_int(0, $height), 40, 40, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
