<?php

namespace App\Composer;

use App\BrandKit\BrandKits;
use App\BrandKit\Kind;
use App\BrandKit\KitFiles;
use App\Catalogue\Cutouts;
use App\Images\GeneratedImage;
use App\Images\ImageFiles;
use App\Models\Brand;
use App\Models\BrandKitItem;
use App\Models\Copy;
use App\Models\CreativeRequest;
use App\Models\LayoutTemplate;
use App\Models\Output;
use App\Models\Placement;
use App\Platforms\AspectRatio;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Makes finished ads: a clean image with the headline, offer, logo, pack
 * shot, button and fine print set on it by the brand's layout, rendered at
 * the placement's exact size, then checked (size, file size, safe zone)
 * in code before anyone sees it.
 */
class Composer
{
    public function __construct(
        private Renderer $renderer,
        private AdHtml $html,
        private Layouts $layouts,
        private BrandKits $kits,
        private KitFiles $kitFiles,
        private ImageFiles $files,
        private Cutouts $cutouts,
    ) {}

    /**
     * One finished ad for a placement, from a clean image, saved as an output.
     *
     * @throws ComposeException
     */
    public function compose(Output $image, Placement $placement, ?LayoutTemplate $template = null, ?string $language = null): Output
    {
        $request = $image->request;
        $brand = $request->brand;
        $template ??= $this->layouts->templateFor($brand, $placement->ratio);
        $language ??= $request->languages[0] ?? 'en';
        $content = $this->content($request, $language, $image->path);

        [$png, $boxes] = $this->render($brand, $placement, $template, $content);
        [$bytes, $mime, $checks] = $this->finish($png, $placement, $boxes, $content);

        $position = (int) Output::where('parent_id', $image->id)->where('placement_id', $placement->id)->where('language', $language)->max('position') + 1;
        $generated = GeneratedImage::fromBytes($bytes, $mime);
        [$path, $thumb] = $this->files->store("requests/{$request->id}/ads/{$placement->id}", "{$image->position}-{$language}-{$position}", $generated);

        return Output::create([
            'request_id' => $request->id, 'generation_id' => $image->generation_id, 'parent_id' => $image->id, 'placement_id' => $placement->id,
            'layout_template_id' => $template->exists ? $template->id : null, 'ratio' => $placement->ratio, 'kind' => Output::AD, 'language' => $language,
            'position' => $position, 'path' => $path, 'thumb_path' => $thumb, 'mime' => $mime, 'width' => $generated->width, 'height' => $generated->height,
            'size' => strlen($bytes), 'cost' => 0, 'checks' => $checks,
        ]);
    }

    /**
     * A sample ad for a layout, to look at while editing it: the brand's
     * kit on a plain backdrop at the shape's usual size.
     *
     * @return string PNG bytes.
     */
    public function preview(Brand $brand, LayoutTemplate $template, string $language = 'en'): string
    {
        $placement = Placement::available()->where('ratio', $template->ratio)->first()
            ?? new Placement(['width' => 1080, 'height' => (int) round(1080 / AspectRatio::value($template->ratio)), 'ratio' => $template->ratio, 'safe_top' => 0, 'safe_bottom' => 0, 'safe_left' => 0, 'safe_right' => 0]);

        $content = $this->kitContent($brand, $language, new AdContent(headline: '20% off this weekend', subline: 'Saturday and Sunday only, code WEEKEND20', cta: 'Shop now', price: '$49.99', legal: 'While supplies last.', language: $language));
        $sample = $this->sampleBackdrop((int) $placement->width, (int) $placement->height);

        [$png] = $this->render($brand, $placement, $template, $content, $sample);

        return $png;
    }

    /**
     * The words from the plan and the pictures from the kit.
     */
    public function content(CreativeRequest $request, string $language, ?string $imagePath): AdContent
    {
        $plan = $request->plan ?? [];
        // The words in this language, when the copywriter has written them; else the plan's (English).
        $words = Copy::where('request_id', $request->id)->where('kind', Copy::AD)->where('language', $language)->where('status', Copy::DONE)->first()?->adText();
        $headline = trim((string) ($words['headline'] ?? '')) ?: (trim((string) data_get($plan, 'offer.headline')) ?: trim((string) ($request->title ?? '')));
        $content = new AdContent(
            headline: $headline,
            subline: $words ? $words['subline'] : trim((string) data_get($plan, 'offer.details')),
            cta: ($words['cta'] ?? '') ?: trim((string) ($plan['cta'] ?? '')),
            price: $request->variant?->price ? '$'.number_format((float) $request->variant->price, 2) : '',
            image: $imagePath,
            language: $language,
        );

        $content = $this->kitContent($request->brand, $language, $content);

        // The pack shot: a transparent one from the kit for this variant, else the cut-out of its store photo (the photos themselves have backgrounds).
        $product = null;
        $note = '';
        if ($request->variant) {
            $shot = $this->kits->items($request->brand, Kind::PackShot)->first(fn (BrandKitItem $i) => $i->product_variant_id === $request->product_variant_id && $i->hasFile());
            $product = $shot?->path ?? $this->cutouts->pathFor($request->variant);
            if (! $product) {
                $note = "No transparent pack shot for {$request->variant->product->title} ({$request->variant->label()}): none in the brand kit and no cut-out of its store photo yet, so the product isn't on the ad.";
            }
        }

        return new AdContent($content->headline, $content->subline, $content->cta, $content->legal, $content->price, $content->image, $content->logo, $product, $content->fonts, $content->colours, $language, $note);
    }

    /**
     * Fills in what comes from the brand kit: logo, fonts, colours, a call to action and a legal line when the content has none.
     */
    private function kitContent(Brand $brand, string $language, AdContent $content): AdContent
    {
        $text = fn (BrandKitItem $i) => trim((string) ($i->value('text')[$language] ?? '')) ?: trim((string) ($i->value('text')['en'] ?? ''));

        $colours = $this->kits->items($brand, Kind::Colour)->mapWithKeys(fn (BrandKitItem $c) => [(string) $c->value('role') => (string) $c->value('hex')])->all();
        $fonts = $this->kits->items($brand, Kind::Font)->map(fn (BrandKitItem $f) => ['family' => (string) $f->value('family'), 'role' => (string) $f->value('role'), 'path' => $f->hasFile() ? $f->path : null])->values()->all();
        $logos = $this->kits->items($brand, Kind::Logo)->filter(fn (BrandKitItem $l) => $l->hasFile() && $l->value('variant') === 'full');
        $logo = ($logos->first(fn (BrandKitItem $l) => $l->value('tone') === 'dark') ?? $logos->first(fn (BrandKitItem $l) => $l->value('tone') === 'any') ?? $logos->first())?->path;
        $cta = $content->cta ?: ($this->kits->items($brand, Kind::Cta)->map($text)->filter()->first() ?? '');
        $legal = $content->legal ?: ($this->kits->items($brand, Kind::LegalLine)->map($text)->filter()->first() ?? '');

        return new AdContent($content->headline, $content->subline, $cta, $legal, $content->price, $content->image, $logo, $content->product, $fonts, $colours, $language, $content->productNote);
    }

    /**
     * Renders the page in a scratch directory holding copies of the assets.
     *
     * @return array{0: string, 1: array<string, array{x: int, y: int, w: int, h: int}>}
     */
    private function render(Brand $brand, Placement $placement, LayoutTemplate $template, AdContent $content, ?string $backdrop = null): array
    {
        $dir = storage_path('app/composer/'.uniqid('ad-', true));
        File::ensureDirectoryExists($dir);

        try {
            $assets = [];
            if ($backdrop !== null) {
                file_put_contents($assets['image'] = "{$dir}/image.png", $backdrop);
            } elseif ($content->image) {
                file_put_contents($assets['image'] = "{$dir}/image.".pathinfo($content->image, PATHINFO_EXTENSION), $this->files->disk()->get($content->image));
            }
            if ($content->logo) {
                file_put_contents($assets['logo'] = "{$dir}/logo.".pathinfo($content->logo, PATHINFO_EXTENSION), $this->kitFiles->disk()->get($content->logo));
            }
            if ($content->product) {
                file_put_contents($assets['product'] = "{$dir}/product.".pathinfo($content->product, PATHINFO_EXTENSION), $this->kitFiles->disk()->get($content->product));
            }
            foreach ($content->fonts as $i => $font) {
                if ($font['path']) {
                    file_put_contents($assets["font-{$i}"] = "{$dir}/font-{$i}.".pathinfo($font['path'], PATHINFO_EXTENSION), $this->kitFiles->disk()->get($font['path']));
                }
            }

            $page = $this->html->build($placement, $template, $content, $assets);
            $png = $this->renderer->render($page['html'], (int) $placement->width, (int) $placement->height, $dir);

            return [$png, $page['boxes']];
        } finally {
            File::deleteDirectory($dir);
        }
    }

    /**
     * The file in the placement's format and under its size limit, and the checks.
     *
     * @param  array<string, array{x: int, y: int, w: int, h: int}>  $boxes
     * @return array{0: string, 1: string, 2: list<array{check: string, ok: bool, note: string}>}
     */
    private function finish(string $png, Placement $placement, array $boxes, AdContent $content): array
    {
        $checks = [];
        $info = @getimagesizefromstring($png);
        $sizeOk = $info && (int) $info[0] === (int) $placement->width && (int) $info[1] === (int) $placement->height;
        $checks[] = ['check' => 'size', 'ok' => $sizeOk, 'note' => $sizeOk ? "{$placement->sizeLabel()} exactly" : 'The picture came out '.($info ? "{$info[0]} × {$info[1]}" : 'unreadable').", not {$placement->sizeLabel()}."];

        $types = $placement->image_types ?: ['jpg', 'png'];
        $maxBytes = $placement->max_image_kb ? (int) $placement->max_image_kb * 1024 : null;
        $bytes = $png;
        $mime = 'image/png';

        if (! in_array('png', $types, true) || ($maxBytes && strlen($bytes) > $maxBytes)) {
            if (in_array('jpg', $types, true) || in_array('jpeg', $types, true)) {
                foreach ([92, 85, 78, 70, 60] as $quality) {
                    $jpeg = $this->jpeg($png, $quality);
                    if ($jpeg === null) {
                        break;
                    }
                    $bytes = $jpeg;
                    $mime = 'image/jpeg';
                    if (! $maxBytes || strlen($bytes) <= $maxBytes) {
                        break;
                    }
                }
            } elseif (in_array('webp', $types, true)) {
                $webp = $this->webp($png);
                if ($webp !== null) {
                    $bytes = $webp;
                    $mime = 'image/webp';
                }
            }
        }

        $kb = (int) round(strlen($bytes) / 1024);
        $fileOk = ! $maxBytes || strlen($bytes) <= $maxBytes;
        $checks[] = ['check' => 'file_size', 'ok' => $fileOk, 'note' => $fileOk ? "{$kb} KB".($placement->max_image_kb ? ", under {$placement->max_image_kb} KB" : '') : "{$kb} KB, over the placement's {$placement->max_image_kb} KB."];

        // Nothing important may sit in the safe zone: every box the layout placed must be inside the clear area.
        $safe = $placement->safeZone();
        $clear = ['x' => $safe['left'], 'y' => $safe['top'], 'w' => $placement->width - $safe['left'] - $safe['right'], 'h' => $placement->height - $safe['top'] - $safe['bottom']];
        $outside = collect($boxes)->reject(fn ($b) => $b['x'] >= $clear['x'] && $b['y'] >= $clear['y'] && $b['x'] + $b['w'] <= $clear['x'] + $clear['w'] && $b['y'] + $b['h'] <= $clear['y'] + $clear['h'])->keys()->all();
        $checks[] = ['check' => 'safe_zone', 'ok' => $outside === [], 'note' => $outside === [] ? ($placement->hasSafeZone() ? 'Clear of the safe zone' : 'No safe zone on this placement') : 'In the safe zone: '.implode(', ', $outside).'.'];

        $checks[] = ['check' => 'product', 'ok' => $content->productNote === '', 'note' => $content->productNote ?: ($content->product ? 'Pack shot placed' : 'No product on this ad')];

        return [$bytes, $mime, $checks];
    }

    private function jpeg(string $png, int $quality): ?string
    {
        try {
            $image = @imagecreatefromstring($png);
            if (! $image) {
                return null;
            }
            $flat = imagecreatetruecolor(imagesx($image), imagesy($image));
            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            ob_start();
            imagejpeg($flat, null, $quality);

            return (string) ob_get_clean() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function webp(string $png): ?string
    {
        try {
            $image = @imagecreatefromstring($png);
            if (! $image) {
                return null;
            }
            imagepalettetotruecolor($image);
            ob_start();
            imagewebp($image, null, 88);

            return (string) ob_get_clean() ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A soft gradient to preview a layout on.
     */
    private function sampleBackdrop(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 4) {
            $t = $y / max(1, $height);
            $colour = imagecolorallocate($image, (int) (90 + 60 * $t), (int) (110 + 40 * $t), (int) (130 + 30 * $t));
            imagefilledrectangle($image, 0, $y, $width, $y + 4, $colour);
        }
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
