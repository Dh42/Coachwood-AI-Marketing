<?php

namespace App\Http\Controllers;

use App\BrandKit\KitFiles;
use App\Models\Brand;
use App\Models\BrandKitItem;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a brand kit's files (logos, reference images, fonts, tracks, pack
 * shots) to people who can see the brand. Files are named by their content,
 * so a new upload is a new address and browsers can keep them for a day.
 */
class BrandKitFileController extends Controller
{
    public function __invoke(Brand $brand, BrandKitItem $item, KitFiles $files, ?string $size = null): Response
    {
        abort_unless($item->brand_id === $brand->id, 404);

        $path = $size === 'thumb' ? $item->thumb_path : $item->path;
        abort_unless($path && $files->disk()->exists($path), 404);

        $headers = [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ($size !== 'thumb' && $item->isSvg()) {
            // An SVG shown on the app's own domain: nothing in it may run or load anything.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'";
            $headers['Content-Type'] = 'image/svg+xml';
        } elseif ($size !== 'thumb' && $item->mime) {
            $headers['Content-Type'] = $item->mime;
        }

        return $files->disk()->response($path, $size === 'thumb' ? 'preview.webp' : $item->original_name, $headers);
    }
}
