<?php

namespace App\Images;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Puts generated images on the app's disk with a WebP thumbnail for the
 * screens, and reads them back for the next step (composing, review).
 */
class ImageFiles
{
    public const THUMB = 480;

    public function disk(): Filesystem
    {
        return Storage::disk();
    }

    /**
     * Stores the image under a directory and returns its path and the
     * thumbnail's (null when a thumbnail couldn't be made).
     *
     * @return array{0: string, 1: ?string}
     */
    public function store(string $directory, string $name, GeneratedImage $image): array
    {
        $path = trim($directory, '/')."/{$name}.{$image->extension()}";
        $this->disk()->put($path, $image->data);

        $thumb = $this->thumb($image->data);
        $thumbPath = null;
        if ($thumb !== null) {
            $thumbPath = trim($directory, '/')."/{$name}.thumb.webp";
            $this->disk()->put($thumbPath, $thumb);
        }

        return [$path, $thumbPath];
    }

    /**
     * A JPEG of the image scaled to fit a number of pixels, for sending to
     * the reviewer (smaller than the original, big enough to read labels).
     */
    public function forReview(string $bytes, int $max = 1024): string
    {
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                return $bytes;
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, $max / max($width, $height));
            $scaled = $scale < 1 ? imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC) : $image;
            $flat = imagecreatetruecolor(imagesx($scaled), imagesy($scaled));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $scaled, 0, 0, 0, 0, imagesx($scaled), imagesy($scaled));
            ob_start();
            imagejpeg($flat, null, 85);

            return (string) ob_get_clean() ?: $bytes;
        } catch (Throwable) {
            return $bytes;
        } finally {
            ini_set('memory_limit', $limit);
        }
    }

    /**
     * WebP bytes of the image scaled to fit THUMB pixels, or null.
     */
    public function thumb(string $bytes, int $max = self::THUMB): ?string
    {
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $image = @imagecreatefromstring($bytes);
            if (! $image) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, $max / max($width, $height));
            $thumb = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
            if (! $thumb) {
                return null;
            }
            imagepalettetotruecolor($thumb);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);

            ob_start();
            $ok = imagewebp($thumb, null, 82);
            $out = (string) ob_get_clean();

            return $ok && $out !== '' ? $out : null;
        } catch (Throwable) {
            return null;
        } finally {
            ini_set('memory_limit', $limit);
        }
    }
}
