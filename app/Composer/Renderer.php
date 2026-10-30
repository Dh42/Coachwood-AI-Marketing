<?php

namespace App\Composer;

/**
 * Turns the composer's HTML into a PNG of exactly the size asked for.
 * Headless Chrome in production; tests bind a stand-in.
 */
interface Renderer
{
    /**
     * @param  string  $directory  A directory holding the HTML's assets (fonts, logo, images), where the renderer may write.
     * @return string PNG bytes.
     *
     * @throws ComposeException
     */
    public function render(string $html, int $width, int $height, string $directory): string;
}
