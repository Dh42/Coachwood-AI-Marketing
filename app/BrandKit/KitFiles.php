<?php

namespace App\BrandKit;

use App\Models\Brand;
use App\Models\BrandKitItem;
use App\Settings\Settings;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The files in a brand kit (logos, reference images, fonts, tracks, pack
 * shots), on the app's file disk (local, or Spaces in production), named by
 * their content, and served by the app itself to people with access to the
 * brand (BrandKitFileController).
 */
class KitFiles
{
    public const ROOT = 'brand-kit';

    /** The longest side of a preview, in pixels. */
    public const THUMB = 480;

    /** Images with more pixels than this get no preview rather than exhausting memory. */
    private const MAX_PIXELS = 40_000_000;

    private const MIMES = [
        'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'ogg' => 'audio/ogg',
    ];

    public function __construct(private Settings $settings) {}

    public function disk(): Filesystem
    {
        return Storage::disk();
    }

    /**
     * Checks an upload against the kind's file spec and the size limit.
     *
     * @throws ValidationException
     */
    public function validate(Kind $kind, UploadedFile $file, string $attribute = 'file'): void
    {
        $spec = $kind->file() ?? throw ValidationException::withMessages([$attribute => "{$kind->title()} don't take files."]);
        $maxKb = max(1, (int) $this->settings->get('brand_kit.max_upload_mb')) * 1024;

        Validator::make([$attribute => $file], [
            $attribute => ['required', 'file', 'max:'.$maxKb, 'extensions:'.implode(',', $spec->extensions)],
        ], [
            "{$attribute}.max" => 'That file is bigger than '.($maxKb / 1024).' MB.',
            "{$attribute}.extensions" => "A {$kind->one()} is a {$spec->describe()} file.",
        ])->validate();

        if (strtolower($file->getClientOriginalExtension()) === 'svg' && ! self::safeSvg((string) file_get_contents($file->getRealPath()))) {
            throw ValidationException::withMessages([$attribute => "This SVG has scripts, links or embedded content in it, so it can't be used. Export it again as a plain SVG, or as a PNG."]);
        }

        if ($spec->type === FileSpec::IMAGE && strtolower($file->getClientOriginalExtension()) !== 'svg' && ! @getimagesize($file->getRealPath())) {
            throw ValidationException::withMessages([$attribute => "That file isn't an image the app can read."]);
        }
    }

    /**
     * Keeps an upload for a brand and returns what the item records about it.
     *
     * @return array{path: string, thumb_path: ?string, original_name: string, mime: string, size: int, width: ?int, height: ?int, transparent: ?bool}
     */
    public function store(Brand $brand, Kind $kind, UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;
        $hash = sha1_file($file->getRealPath());
        $dir = self::ROOT."/{$brand->id}/{$kind->value}";
        $path = "{$dir}/{$hash}.{$extension}";

        $this->disk()->putFileAs($dir, $file, "{$hash}.{$extension}");

        $raster = in_array($extension, ['png', 'jpg', 'webp', 'gif'], true);
        [$width, $height] = $raster ? $this->dimensions($file->getRealPath()) : ($extension === 'svg' ? self::svgSize((string) file_get_contents($file->getRealPath())) : [null, null]);
        $image = $raster && $width && $height && $width * $height <= self::MAX_PIXELS ? $this->open($file->getRealPath()) : null;
        $thumb = null;

        if ($image) {
            $thumb = "{$dir}/{$hash}.thumb.webp";
            if (! $this->writeThumb($image, $thumb)) {
                $thumb = null;
            }
        }

        return [
            'path' => $path,
            'thumb_path' => $thumb,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => self::MIMES[$extension] ?? ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'transparent' => $image && in_array($extension, ['png', 'webp', 'gif'], true) ? self::hasTransparentEdge($image) : null,
        ];
    }

    /**
     * Removes an item's files, unless another item still uses the same one.
     */
    public function delete(BrandKitItem $item): void
    {
        foreach (array_filter([$item->path, $item->thumb_path]) as $path) {
            $shared = BrandKitItem::where('id', '!=', $item->id)->where(fn ($q) => $q->where('path', $path)->orWhere('thumb_path', $path))->exists();
            if (! $shared) {
                $this->disk()->delete($path);
            }
        }
    }

    /**
     * An SVG that can't run anything when shown in a browser: no scripts,
     * no event handlers, no links out, nothing embedded.
     */
    public static function safeSvg(string $svg): bool
    {
        if (! preg_match('/<svg[\s>]/i', $svg)) {
            return false;
        }

        return ! preg_match('/<\s*(script|foreignObject|iframe|embed|object)\b|\bon[a-z]+\s*=|javascript:|xlink:href\s*=\s*["\']?\s*(https?:|data:text)|\bhref\s*=\s*["\']?\s*(https?:|data:text)|<!ENTITY|<\?xml-stylesheet/i', $svg);
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(string $path): array
    {
        $info = @getimagesize($path);

        return $info ? [(int) $info[0], (int) $info[1]] : [null, null];
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private static function svgSize(string $svg): array
    {
        if (preg_match('/<svg[^>]*\bviewBox\s*=\s*["\']\s*[\d.\-]+[\s,]+[\d.\-]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $svg, $m)) {
            return [(int) round((float) $m[1]), (int) round((float) $m[2])];
        }
        if (preg_match('/<svg[^>]*\bwidth\s*=\s*["\']([\d.]+)(px)?["\']/i', $svg, $w) && preg_match('/<svg[^>]*\bheight\s*=\s*["\']([\d.]+)(px)?["\']/i', $svg, $h)) {
            return [(int) round((float) $w[1]), (int) round((float) $h[1])];
        }

        return [null, null];
    }

    private function open(string $path): ?GdImage
    {
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $image = @imagecreatefromstring((string) file_get_contents($path));

            return $image ?: null;
        } catch (Throwable) {
            return null;
        } finally {
            ini_set('memory_limit', $limit);
        }
    }

    private function writeThumb(GdImage $image, string $path): bool
    {
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::THUMB / max($width, $height));
            $thumb = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);
            if (! $thumb) {
                return false;
            }
            imagepalettetotruecolor($thumb);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);

            ob_start();
            $ok = imagewebp($thumb, null, 82);
            $bytes = (string) ob_get_clean();

            return $ok && $bytes !== '' && $this->disk()->put($path, $bytes);
        } catch (Throwable) {
            return false;
        } finally {
            ini_set('memory_limit', $limit);
        }
    }

    /**
     * Whether the image's edges are see-through, which a logo or a pack shot
     * needs to sit on a scene. Samples the corners and the middle of each side.
     */
    public static function hasTransparentEdge(GdImage $image): bool
    {
        $w = imagesx($image) - 1;
        $h = imagesy($image) - 1;
        $points = [[0, 0], [$w, 0], [0, $h], [$w, $h], [intdiv($w, 2), 0], [intdiv($w, 2), $h], [0, intdiv($h, 2)], [$w, intdiv($h, 2)]];

        foreach ($points as [$x, $y]) {
            $pixel = imagecolorat($image, $x, $y);
            $alpha = imageistruecolor($image) ? ($pixel >> 24) & 0x7F : imagecolorsforindex($image, $pixel)['alpha'];
            if ($alpha >= 64) {
                return true;
            }
        }

        return false;
    }
}
