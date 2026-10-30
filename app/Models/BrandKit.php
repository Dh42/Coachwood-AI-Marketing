<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A brand's voice and visual direction, the written part of its kit. The
 * rest of the kit is BrandKitItem rows (App\BrandKit\Kind).
 *
 * voice: {tone: {en, fr}, use: {en, fr}, avoid: {en, fr}}
 * direction: {mood, lighting, settings, people, avoid}
 */
#[Fillable(['brand_id', 'voice', 'direction', 'updated_by'])]
class BrandKit extends Model
{
    public const VOICE = ['tone', 'use', 'avoid'];

    public const DIRECTION = ['mood', 'lighting', 'settings', 'people', 'avoid'];

    protected function casts(): array
    {
        return ['voice' => 'array', 'direction' => 'array'];
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function voice(string $part, string $lang = 'en'): string
    {
        return trim((string) ($this->voice[$part][$lang] ?? ''));
    }

    public function direction(string $part): string
    {
        return trim((string) ($this->direction[$part] ?? ''));
    }

    /**
     * An empty kit's text, so every field exists.
     *
     * @return array{voice: array<string, array{en: string, fr: string}>, direction: array<string, string>}
     */
    public static function blank(): array
    {
        return [
            'voice' => array_fill_keys(self::VOICE, ['en' => '', 'fr' => '']),
            'direction' => array_fill_keys(self::DIRECTION, ''),
        ];
    }
}
