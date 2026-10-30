<?php

namespace App\Composer;

use App\Models\LayoutTemplate;
use App\Models\Placement;

/**
 * Builds the page the renderer screenshots: the clean image as the
 * background, then the words, product, logo, button and fine print placed
 * by the layout inside the placement's safe zone. Every box is worked out
 * in pixels here, which is also what the safe-zone check reads.
 */
class AdHtml
{
    /**
     * @param  array<string, string>  $assets  Asset name => file path the page may load (image, logo, product, font-*).
     * @return array{html: string, boxes: array<string, array{x: int, y: int, w: int, h: int}>}
     */
    public function build(Placement $placement, LayoutTemplate $template, AdContent $content, array $assets): array
    {
        $W = (int) $placement->width;
        $H = (int) $placement->height;
        $s = fn (string $key) => $template->setting($key);
        $safe = $placement->safeZone();
        $margin = (int) round(min($W, $H) * 0.05);

        // The content area: inside the safe zone, with a margin.
        $cx = $safe['left'] + $margin;
        $cy = $safe['top'] + $margin;
        $cw = $W - $safe['left'] - $safe['right'] - 2 * $margin;
        $ch = $H - $safe['top'] - $safe['bottom'] - 2 * $margin;

        $base = min($W, $H);
        $headlineSize = (int) round($base * ['small' => 0.07, 'medium' => 0.09, 'large' => 0.11][$s('text_size')] ?? 0.09);
        $bodySize = (int) round($headlineSize * 0.42);
        $primary = $content->colours['primary'] ?? '#111111';
        $textColour = match ($s('text_colour')) {
            'dark' => '#111111',
            'primary' => $primary,
            default => '#ffffff',
        };
        $boxes = [];

        // The product takes a side (or the bottom); the words get the rest.
        $productPos = $content->product ? $s('product_position') : 'none';
        $productSize = ['small' => 0.35, 'medium' => 0.5, 'large' => 0.65][$s('product_size')] ?? 0.5;
        $textX = $cx;
        $textW = $cw;
        $textY = $cy;
        $textH = $ch;
        $product = null;
        if ($productPos === 'left' || $productPos === 'right') {
            $pw = (int) round($cw * 0.42);
            $ph = (int) round($ch * $productSize);
            $product = ['x' => $productPos === 'left' ? $cx : $cx + $cw - $pw, 'y' => $cy + (int) round(($ch - $ph) / 2), 'w' => $pw, 'h' => $ph];
            $textW = $cw - $pw - $margin;
            $textX = $productPos === 'left' ? $cx + $pw + $margin : $cx;
        } elseif ($productPos === 'bottom') {
            $ph = (int) round($ch * $productSize);
            $product = ['x' => $cx, 'y' => $cy + $ch - $ph, 'w' => $cw, 'h' => $ph];
            $textH = $ch - $ph - $margin;
        }
        if ($product) {
            $boxes['product'] = $product;
        }

        // The logo in a corner of the content area; the words keep clear of it when it's on their side.
        $logo = null;
        if ($content->logo && $s('logo_corner') !== 'none') {
            $lw = (int) round($W * (['small' => 0.14, 'medium' => 0.2, 'large' => 0.26][$s('logo_size')] ?? 0.2));
            $lh = (int) round($lw * 0.4);
            [$v, $h] = explode('-', $s('logo_corner'));
            $logo = ['x' => $h === 'left' ? $cx : $cx + $cw - $lw, 'y' => $v === 'top' ? $cy : $cy + $ch - $lh, 'w' => $lw, 'h' => $lh];
            $boxes['logo'] = $logo;
            if ($v === 'top' && $s('headline_position') === 'top') {
                $textY += $lh + $margin;
                $textH -= $lh + $margin;
            } elseif ($v === 'bottom' && $s('headline_position') === 'bottom' && $productPos !== 'bottom') {
                $textH -= $lh + $margin;
            }
        }

        $boxes['text'] = ['x' => $textX, 'y' => $textY, 'w' => $textW, 'h' => max(1, $textH)];
        $justify = ['top' => 'flex-start', 'middle' => 'center', 'bottom' => 'flex-end'][$s('headline_position')] ?? 'flex-end';
        $align = $s('align') === 'center' ? 'center' : 'left';
        $items = $s('align') === 'center' ? 'center' : 'flex-start';

        $overlay = match ($s('overlay')) {
            'top' => 'linear-gradient(to bottom, rgba(0,0,0,.55), rgba(0,0,0,0) 55%)',
            'bottom' => 'linear-gradient(to top, rgba(0,0,0,.6), rgba(0,0,0,0) 55%)',
            'both' => 'linear-gradient(to bottom, rgba(0,0,0,.5), rgba(0,0,0,0) 35%, rgba(0,0,0,0) 65%, rgba(0,0,0,.55))',
            default => 'none',
        };

        $faces = '';
        $headlineFamily = 'sans-serif';
        $bodyFamily = 'sans-serif';
        $links = '';
        foreach ($content->fonts as $i => $font) {
            $family = 'Kit'.ucfirst($font['role']);
            if ($font['path'] && isset($assets["font-{$i}"])) {
                $faces .= "@font-face{font-family:'{$family}';src:url('".basename($assets["font-{$i}"])."');font-weight:100 900;}\n";
            } else {
                $links .= '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family='.rawurlencode($font['family']).':wght@400;700;800&display=block">';
                $family = $font['family'];
            }
            if ($font['role'] === 'headline') {
                $headlineFamily = "'{$family}', sans-serif";
            } else {
                $bodyFamily = "'{$family}', sans-serif";
            }
        }

        $e = fn (?string $t) => htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
        $ctaStyle = $s('cta') === 'button'
            ? "display:inline-block;background:{$primary};color:#fff;padding:".round($bodySize * 0.7).'px '.round($bodySize * 1.4).'px;border-radius:999px;font-weight:700;font-size:'.round($bodySize * 1.15).'px;'
            : "display:inline-block;color:{$textColour};font-weight:700;font-size:".round($bodySize * 1.15).'px;text-decoration:underline;';

        $html = '<!doctype html><html><head><meta charset="utf-8">'.$links.'<style>'.$faces.
            "html,body{margin:0;padding:0;width:{$W}px;height:{$H}px;overflow:hidden;background:#000;}".
            '.bg{position:absolute;inset:0;background:url(\''.basename($assets['image'] ?? '').'\') center/cover no-repeat;}'.
            ".overlay{position:absolute;inset:0;background:{$overlay};}".
            ".text{position:absolute;left:{$textX}px;top:{$textY}px;width:{$textW}px;height:{$boxes['text']['h']}px;display:flex;flex-direction:column;justify-content:{$justify};align-items:{$items};text-align:{$align};color:{$textColour};font-family:{$bodyFamily};}".
            ".headline{font-family:{$headlineFamily};font-weight:800;font-size:{$headlineSize}px;line-height:1.05;letter-spacing:-0.01em;text-shadow:0 2px 12px rgba(0,0,0,.25);margin:0;}".
            ".subline{font-size:{$bodySize}px;line-height:1.3;margin:".round($bodySize * 0.6).'px 0 0;opacity:.95;}'.
            '.cta{margin-top:'.round($bodySize * 1.2)."px;{$ctaStyle}}".
            ".legal{position:absolute;left:{$cx}px;width:{$cw}px;bottom:".($H - $cy - $ch).'px;font-size:'.max(10, round($bodySize * 0.6))."px;color:{$textColour};opacity:.8;font-family:{$bodyFamily};}".
            '.product{position:absolute;object-fit:contain;filter:drop-shadow(0 12px 24px rgba(0,0,0,.35));}'.
            '.logo{position:absolute;object-fit:contain;object-position:'.($logo && str_ends_with($s('logo_corner'), 'right') ? 'right' : 'left').' center;}'.
            ".badge{position:absolute;right:{$cx}px;top:{$cy}px;background:{$primary};color:#fff;font-family:{$headlineFamily};font-weight:800;font-size:".round($bodySize * 1.3).'px;padding:'.round($bodySize * 0.8).'px '.round($bodySize * 1.1).'px;border-radius:999px;}'.
            '</style></head><body>';

        $html .= '<div class="bg"></div><div class="overlay"></div>';
        $html .= '<div class="text"><h1 class="headline">'.$e($content->headline).'</h1>';
        if ($s('subline') && $content->subline !== '') {
            $html .= '<p class="subline">'.$e($content->subline).'</p>';
        }
        if ($s('cta') !== 'none' && $content->cta !== '') {
            $html .= '<span class="cta">'.$e($content->cta).'</span>';
        }
        $html .= '</div>';

        if ($product && isset($assets['product'])) {
            $html .= '<img class="product" src="'.basename($assets['product'])."\" style=\"left:{$product['x']}px;top:{$product['y']}px;width:{$product['w']}px;height:{$product['h']}px\">";
        }
        if ($logo && isset($assets['logo'])) {
            $html .= '<img class="logo" src="'.basename($assets['logo'])."\" style=\"left:{$logo['x']}px;top:{$logo['y']}px;width:{$logo['w']}px;height:{$logo['h']}px\">";
        }
        if ($s('price_badge') && $content->price !== '' && ! ($logo && $s('logo_corner') === 'top-right')) {
            $html .= '<div class="badge">'.$e($content->price).'</div>';
            $boxes['badge'] = ['x' => $cx + $cw - (int) round($bodySize * 6), 'y' => $cy, 'w' => (int) round($bodySize * 6), 'h' => (int) round($bodySize * 3)];
        }
        if ($s('legal') && $content->legal !== '') {
            $html .= '<p class="legal">'.$e($content->legal).'</p>';
            $boxes['legal'] = ['x' => $cx, 'y' => $cy + $ch - (int) round($bodySize), 'w' => $cw, 'h' => (int) round($bodySize)];
        }

        $html .= '</body></html>';

        return ['html' => $html, 'boxes' => $boxes];
    }
}
