<?php

namespace App\Http\Controllers;

use App\Images\ImageFiles;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Output;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a request's outputs (and their thumbnails) to people who may
 * see the request; the full image downloads with a readable name.
 */
class OutputFileController extends Controller
{
    public function __invoke(Brand $brand, CreativeRequest $request, Output $output, ImageFiles $files, ?string $size = null): Response
    {
        abort_unless($request->brand_id === $brand->id && $output->request_id === $request->id, 404);
        Gate::authorize('view', $request);

        $path = $size === 'thumb' ? $output->thumb_path : $output->path;
        abort_unless($path && $files->disk()->exists($path), 404);

        $headers = ['Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $size === 'thumb' ? 'image/webp' : $output->mime];

        return $size === 'thumb' || ! request()->boolean('download')
            ? $files->disk()->response($path, $size === 'thumb' ? 'preview.webp' : $output->downloadName(), $headers)
            : $files->disk()->download($path, $output->downloadName(), $headers);
    }
}
