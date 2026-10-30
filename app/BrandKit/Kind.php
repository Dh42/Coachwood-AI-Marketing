<?php

namespace App\BrandKit;

use App\Models\Brand;
use App\Models\BrandKitItem;
use App\Models\ProductVariant;

/**
 * The kinds of item in a brand kit, with what the team provides for each
 * (the brief's table). The kit page draws every kind from this.
 */
enum Kind: string
{
    case Logo = 'logo';
    case Colour = 'colour';
    case Font = 'font';
    case Reference = 'reference';
    case BestAd = 'best_ad';
    case BadExample = 'bad_example';
    case Cta = 'cta';
    case LegalLine = 'legal_line';
    case Music = 'music';
    case PackShot = 'pack_shot';

    /** Image files that can't carry scripts. */
    private const RASTER = ['png', 'jpg', 'jpeg', 'webp', 'gif'];

    public function title(): string
    {
        return match ($this) {
            self::Logo => 'Logos',
            self::Colour => 'Colours',
            self::Font => 'Fonts',
            self::Reference => 'Style references',
            self::BestAd => 'Best-performing ads',
            self::BadExample => 'Bad examples',
            self::Cta => 'Calls to action',
            self::LegalLine => 'Legal lines',
            self::Music => 'Music',
            self::PackShot => 'Pack shots',
        };
    }

    /**
     * One item, in words ("logo", "colour").
     */
    public function one(): string
    {
        return match ($this) {
            self::Logo => 'logo',
            self::Colour => 'colour',
            self::Font => 'font',
            self::Reference => 'reference image',
            self::BestAd => 'ad',
            self::BadExample => 'example',
            self::Cta => 'call to action',
            self::LegalLine => 'legal line',
            self::Music => 'track',
            self::PackShot => 'pack shot',
        };
    }

    /**
     * What to provide, in plain words, from the brief.
     */
    public function help(): string
    {
        return match ($this) {
            self::Logo => 'The full logo and the icon-only mark, each as an SVG or a PNG with a transparent background, with a version for light backgrounds and one for dark where they differ. The composer puts these on finished ads; they are never generated.',
            self::Colour => 'The brand colours as hex codes, and whether each is for text, backgrounds or both. Finished ads and the review use them.',
            self::Font => 'The headline and body fonts: a font file, or the name of a Google Font. Only fonts whose licence allows use in ads.',
            self::Reference => 'Ten to twenty images that look right: past campaigns, photography, work you admire. The planner shows these to the image model as the look to match.',
            self::BestAd => 'Past ads that worked, with a note on why. They tell the planner and the reviewer what good looks like for this brand.',
            self::BadExample => 'Images that are off-brand, with a note on why, so the reviewer knows what to reject.',
            self::Cta => 'Approved calls to action, in each language. Copy picks from this list rather than inventing its own.',
            self::LegalLine => 'Standard fine print, trademark symbols and disclaimers, with when each applies. The composer typesets them; they are never generated.',
            self::Music => 'Licensed tracks the brand can use in video. Needed for phase 3, video.',
            self::PackShot => 'Only where the store\'s product photos aren\'t clean enough: a front-facing PNG with a transparent background, tied to the product variant it shows. The composer uses it instead of the store photo\'s cut-out.',
        };
    }

    public function need(): Need
    {
        return match ($this) {
            self::Logo, self::Colour, self::Font, self::Reference => Need::Required,
            self::BestAd, self::BadExample, self::Cta => Need::Recommended,
            self::LegalLine, self::PackShot => Need::Optional,
            self::Music => Need::ForVideo,
        };
    }

    public function file(): ?FileSpec
    {
        return match ($this) {
            self::Logo => new FileSpec(['svg', 'png', 'webp']),
            self::Reference, self::BestAd, self::BadExample => new FileSpec(self::RASTER),
            self::PackShot => new FileSpec(['png', 'webp']),
            self::Font => new FileSpec(['woff2', 'woff', 'ttf', 'otf'], FileSpec::FONT, required: false),
            self::Music => new FileSpec(['mp3', 'wav', 'm4a', 'aac', 'ogg'], FileSpec::AUDIO),
            self::Colour, self::Cta, self::LegalLine => null,
        };
    }

    /**
     * Whether an item needs a file to exist at all.
     */
    public function needsFile(): bool
    {
        return $this->file()?->required ?? false;
    }

    /**
     * @return list<Field>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Logo => [
                new Field('variant', 'Which logo', FieldType::Select, required: true, options: ['full' => 'Full logo', 'mark' => 'Icon-only mark'], default: 'full'),
                new Field('tone', 'Made for', FieldType::Select, required: true, options: ['any' => 'Any background', 'light' => 'Light backgrounds', 'dark' => 'Dark backgrounds'], default: 'any'),
            ],
            self::Colour => [
                new Field('name', 'Name', required: true, placeholder: 'BioSteel red'),
                new Field('hex', 'Hex code', required: true, rules: ['regex:/^#[0-9a-fA-F]{6}$/'], placeholder: '#E4002B', messages: ['regex' => 'A hex code is # and six digits or letters, like #E4002B.']),
                new Field('role', 'Role', FieldType::Select, required: true, options: ['primary' => 'Primary', 'secondary' => 'Secondary', 'accent' => 'Accent', 'neutral' => 'Neutral'], default: 'primary'),
                new Field('use', 'Use for', FieldType::Select, required: true, options: ['both' => 'Text and backgrounds', 'text' => 'Text', 'background' => 'Backgrounds'], default: 'both'),
            ],
            self::Font => [
                new Field('role', 'Used for', FieldType::Select, required: true, options: ['headline' => 'Headlines', 'body' => 'Body text'], default: 'headline'),
                new Field('family', 'Font name', required: true, help: 'A Google Font\'s name, or the name of the uploaded font.', placeholder: 'Montserrat'),
                new Field('licensed', 'The licence allows use in ads', FieldType::Toggle),
                new Field('licence', 'Where it\'s licensed from', placeholder: 'Google Fonts (open licence), Adobe Fonts, bought from…'),
            ],
            self::Reference => [new Field('note', 'What\'s right about it', placeholder: 'Natural light, real athletes, product small but clear')],
            self::BestAd => [new Field('note', 'Why it worked')],
            self::BadExample => [new Field('note', 'Why it\'s off-brand')],
            self::Cta => [new Field('text', 'Wording', FieldType::Translated, required: true, rules: ['max:60'])],
            self::LegalLine => [
                new Field('text', 'Text', FieldType::Translated, required: true),
                new Field('when', 'When to add it', placeholder: 'Whenever a price or discount is shown'),
            ],
            self::Music => [
                new Field('title', 'Title', required: true),
                new Field('licence', 'Licence', help: 'Where it\'s licensed from and what the licence allows.'),
            ],
            self::PackShot => [
                new Field('product_variant_id', 'Product variant', FieldType::Select, options: fn (Brand $brand) => self::variantOptions($brand), help: 'Which product and size this shows. Without one it can\'t replace the store photo.'),
                new Field('note', 'Note'),
            ],
        };
    }

    public function field(string $key): ?Field
    {
        return collect($this->fields())->first(fn (Field $f) => $f->key === $key);
    }

    /**
     * An item in words, for the activity log and the kit's summary.
     */
    public function label(BrandKitItem $item): string
    {
        $get = fn (string $key) => $item->value($key);
        $option = fn (string $key) => $this->field($key)?->optionsFor($item->brand)[$get($key)] ?? (string) $get($key);

        return match ($this) {
            self::Logo => strtolower($option('variant')).($get('tone') !== 'any' ? ' for '.strtolower($option('tone')) : ''),
            self::Colour => trim($get('name').' '.$get('hex')),
            self::Font => $get('family').' ('.strtolower($option('role')).')',
            self::Cta, self::LegalLine => $get('text')['en'] ?? '',
            self::Music => $get('title') ?: (string) $item->original_name,
            self::PackShot => $item->variant ? $item->variant->product->title.' · '.$item->variant->label() : (string) $item->original_name,
            default => (string) $item->original_name,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function variantOptions(Brand $brand): array
    {
        return ['' => 'Not tied to a product yet'] + ProductVariant::with('product')->whereNull('removed_at')
            ->whereHas('product', fn ($q) => $q->where('brand_id', $brand->id)->whereNull('removed_at'))
            ->get()->sortBy(fn (ProductVariant $v) => [$v->product->title, $v->position])
            ->mapWithKeys(fn (ProductVariant $v) => [(string) $v->id => "{$v->product->title} · {$v->label()}"])->all();
    }

    /**
     * The order the kit page shows the kinds in, grouped.
     *
     * @return array<string, list<self>>
     */
    public static function groups(): array
    {
        return [
            'Identity' => [self::Logo, self::Colour, self::Font],
            'Examples' => [self::Reference, self::BestAd, self::BadExample],
            'Words' => [self::Cta, self::LegalLine],
            'Media' => [self::PackShot, self::Music],
        ];
    }
}
