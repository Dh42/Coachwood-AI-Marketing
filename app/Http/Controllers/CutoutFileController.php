<?php

namespace App\Http\Controllers;

use App\Catalogue\Cutouts;
use App\Models\Brand;
use App\Models\ProductImage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a store photo's cut-out to people who may see the brand.
 */
class CutoutFileController extends Controller
{
    public function __invoke(Brand $brand, ProductImage $image, Cutouts $cutouts): Response
    {
        abort_unless($image->product->brand_id === $brand->id && $image->cutout_path && $cutouts->disk()->exists($image->cutout_path), 404);

        return $cutouts->disk()->response($image->cutout_path, "cutout-{$image->id}.png", ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'image/png']);
    }
}
