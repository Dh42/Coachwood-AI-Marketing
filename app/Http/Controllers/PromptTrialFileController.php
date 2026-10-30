<?php

namespace App\Http\Controllers;

use App\Images\ImageFiles;
use App\Models\Brand;
use App\Prompts\PromptTrials;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an image made by "Try it" on the Prompts page to the people who
 * may edit the brand's prompts. The files live a day.
 */
class PromptTrialFileController extends Controller
{
    public function __invoke(Brand $brand, string $file, ImageFiles $files): Response
    {
        $path = PromptTrials::DIR."/{$brand->id}/{$file}";
        abort_unless($files->disk()->exists($path), 404);

        $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/png',
        };

        return $files->disk()->response($path, $file, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => $mime]);
    }
}
