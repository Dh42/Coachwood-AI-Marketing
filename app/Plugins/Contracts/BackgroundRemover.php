<?php

namespace App\Plugins\Contracts;

use App\Catalogue\Cutout;
use App\Catalogue\CutoutException;
use App\Models\Brand;
use App\Models\Connection;

/**
 * A plugin that cuts the subject out of a photo, leaving a see-through
 * background (Photoroom first). App\Catalogue\Cutouts uses it to make one
 * cut-out per store photo, kept on the disk, so the composer and the
 * reviewer have a real pack shot when the brand kit has none.
 */
interface BackgroundRemover
{
    /**
     * Removes the background from one photo and returns a PNG with
     * transparency, cropped to the subject, and what the call cost.
     *
     * @throws CutoutException
     */
    public function removeBackground(Connection $connection, string $bytes, string $mime): Cutout;
}
