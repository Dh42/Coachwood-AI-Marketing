<?php

namespace App\BrandKit;

use App\Models\Brand;
use App\Models\BrandKit;
use App\Models\BrandKitItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A brand's kit: the written voice and visual direction (BrandKit), and the
 * items of each kind (BrandKitItem, App\BrandKit\Kind). Says what's still
 * missing before the brand can generate, and sums the kit up for the prompts.
 */
class BrandKits
{
    public function __construct(private KitFiles $files, private Settings $settings) {}

    public function for(Brand $brand): BrandKit
    {
        return BrandKit::firstOrCreate(['brand_id' => $brand->id], BrandKit::blank());
    }

    /**
     * @return Collection<int, BrandKitItem>
     */
    public function items(Brand $brand, Kind $kind): Collection
    {
        return BrandKitItem::with($kind === Kind::PackShot ? ['variant.product'] : [])->where('brand_id', $brand->id)->where('kind', $kind)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * Saves the voice and visual direction.
     *
     * @param  array<string, array{en?: mixed, fr?: mixed}>  $voice
     * @param  array<string, mixed>  $direction
     *
     * @throws ValidationException
     */
    public function saveText(Brand $brand, array $voice, array $direction, User $by): BrandKit
    {
        $rules = [];
        foreach (BrandKit::VOICE as $part) {
            $rules["voice.{$part}.en"] = $rules["voice.{$part}.fr"] = ['nullable', 'string', 'max:5000'];
        }
        foreach (BrandKit::DIRECTION as $part) {
            $rules["direction.{$part}"] = ['nullable', 'string', 'max:5000'];
        }
        Validator::make(['voice' => $voice, 'direction' => $direction], $rules)->validate();

        $kit = $this->for($brand);
        $clean = BrandKit::blank();
        foreach (BrandKit::VOICE as $part) {
            $clean['voice'][$part] = ['en' => trim((string) ($voice[$part]['en'] ?? '')), 'fr' => trim((string) ($voice[$part]['fr'] ?? ''))];
        }
        foreach (BrandKit::DIRECTION as $part) {
            $clean['direction'][$part] = trim((string) ($direction[$part] ?? ''));
        }

        $kit->fill([...$clean, 'updated_by' => $by->id])->save();

        if ($kit->wasChanged(['voice', 'direction'])) {
            activity('brand-kit')->causedBy($by)->performedOn($brand)->event('text_saved')->log("Changed {$brand->name}'s voice and visual direction");
        }

        return $kit;
    }

    /**
     * Saves the items of one kind as the page shows them: each row's fields,
     * in order. Rows with an id update that item; rows without one are new
     * (only for kinds that need no file). Items left out are untouched.
     *
     * @param  array<string|int, array<string, mixed>>  $rows  Keyed as the page keys them; errors come back under those keys.
     *
     * @throws ValidationException
     */
    public function saveRows(Brand $brand, Kind $kind, array $rows, User $by): void
    {
        $existing = $this->items($brand, $kind)->keyBy('id');
        $rules = [];
        $attributes = [];
        $messages = [];

        foreach ($rows as $key => $row) {
            $rules["{$key}.id"] = ['nullable', 'integer', fn ($attr, $value, $fail) => $value && ! $existing->has((int) $value) ? $fail("That {$kind->one()} is gone.") : null];
            foreach ($kind->fields() as $field) {
                foreach ($field->rulesFor($brand) as $path => $fieldRules) {
                    $rules["{$key}.{$path}"] = $fieldRules;
                    $attributes["{$key}.{$path}"] = strtolower($field->label).(str_ends_with($path, '.fr') ? ' (French)' : '');
                }
                foreach ($field->messages as $rule => $message) {
                    $messages["{$key}.{$field->key}.{$rule}"] = $message;
                    $messages["{$key}.{$field->key}.en.{$rule}"] = $message;
                    $messages["{$key}.{$field->key}.fr.{$rule}"] = $message;
                }
            }
        }

        Validator::make($rows, $rules, $messages, $attributes)->validate();

        DB::transaction(function () use ($brand, $kind, $rows, $by, $existing) {
            $position = 0;
            $saved = 0;

            foreach ($rows as $row) {
                $data = [];
                foreach ($kind->fields() as $field) {
                    $data[$field->key] = $field->clean($row[$field->key] ?? null);
                }

                if ($id = (int) ($row['id'] ?? 0)) {
                    $item = $existing->get($id);
                    $item->fill([
                        'position' => $position++,
                        'data' => [...$item->data, ...$data],
                        'product_variant_id' => $this->variantId($brand, $data),
                    ]);
                    $saved += (int) $item->isDirty();
                    $item->save();
                } elseif (! $kind->needsFile()) {
                    BrandKitItem::create([
                        'brand_id' => $brand->id, 'kind' => $kind, 'position' => $position++, 'data' => $data,
                        'product_variant_id' => $this->variantId($brand, $data), 'created_by' => $by->id,
                    ]);
                    $saved++;
                }
            }

            if ($saved > 0) {
                activity('brand-kit')->causedBy($by)->performedOn($brand)->event('items_saved')->withProperties(['kind' => $kind->value, 'count' => count($rows)])
                    ->log("Saved {$brand->name}'s ".strtolower($kind->title()).' ('.count($rows).')');
            }
        });
    }

    /**
     * Adds an item from an uploaded file, with the kind's default fields.
     *
     * @param  array<string, mixed>  $data  Field values to start with.
     *
     * @throws ValidationException
     */
    public function addFile(Brand $brand, Kind $kind, UploadedFile $file, User $by, array $data = []): BrandKitItem
    {
        $this->files->validate($kind, $file);
        $stored = $this->files->store($brand, $kind, $file);

        $fields = [];
        foreach ($kind->fields() as $field) {
            $fields[$field->key] = $field->clean($data[$field->key] ?? $field->blank());
        }
        if ($stored['transparent'] !== null) {
            $fields['transparent'] = $stored['transparent'];
        }
        unset($stored['transparent']);

        $item = BrandKitItem::create([
            ...$stored,
            'brand_id' => $brand->id,
            'kind' => $kind,
            'position' => (int) BrandKitItem::where('brand_id', $brand->id)->where('kind', $kind)->max('position') + 1,
            'data' => $fields,
            'product_variant_id' => $this->variantId($brand, $fields),
            'created_by' => $by->id,
        ]);

        activity('brand-kit')->causedBy($by)->performedOn($brand)->event('file_added')->withProperties(['kind' => $kind->value, 'item' => $item->id, 'file' => $item->original_name])
            ->log("Added a {$kind->one()} to {$brand->name}'s kit: {$item->original_name}");

        return $item;
    }

    /**
     * Puts a file on an item that has none, or swaps the one it has.
     *
     * @throws ValidationException
     */
    public function attachFile(BrandKitItem $item, UploadedFile $file, User $by): BrandKitItem
    {
        $this->files->validate($item->kind, $file);
        $old = clone $item;
        $stored = $this->files->store($item->brand, $item->kind, $file);

        $data = $item->data;
        if ($stored['transparent'] !== null) {
            $data['transparent'] = $stored['transparent'];
        }
        unset($stored['transparent']);
        $item->fill([...$stored, 'data' => $data])->save();

        if ($old->path && $old->path !== $item->path) {
            $this->files->delete($old);
        }

        activity('brand-kit')->causedBy($by)->performedOn($item->brand)->event('file_added')->withProperties(['kind' => $item->kind->value, 'item' => $item->id, 'file' => $item->original_name])
            ->log("Added a file to {$item->brand->name}'s {$item->kind->one()} {$item->label()}: {$item->original_name}");

        return $item;
    }

    public function remove(BrandKitItem $item, User $by): void
    {
        $label = $item->label();
        $brand = $item->brand;

        $this->files->delete($item);
        $item->delete();

        activity('brand-kit')->causedBy($by)->performedOn($brand)->event('removed')->withProperties(['kind' => $item->kind->value, 'label' => $label])
            ->log("Removed the {$item->kind->one()} \"{$label}\" from {$brand->name}'s kit");
    }

    /**
     * What the kit has and what it still needs, in the order the page shows
     * it. A required check that fails stops the brand generating.
     *
     * @return list<array{key: string, title: string, need: Need, ok: bool, detail: string}>
     */
    public function readiness(Brand $brand): array
    {
        $kit = $this->for($brand);
        $count = fn (Kind $kind) => BrandKitItem::where('brand_id', $brand->id)->where('kind', $kind)->get();
        $min = max(0, (int) $this->settings->get('brand_kit.min_references', $brand->id));
        $plural = fn (int $n, string $one, ?string $many = null) => $n.' '.($n === 1 ? $one : ($many ?? $one.'s'));

        $logos = $count(Kind::Logo);
        $colours = $count(Kind::Colour);
        $fonts = $count(Kind::Font)->filter(fn (BrandKitItem $f) => $f->value('family') !== '' || $f->hasFile());
        $fontOk = fn (string $role) => $fonts->contains(fn (BrandKitItem $f) => $f->value('role') === $role && $f->value('licensed'));
        $references = $count(Kind::Reference)->count();
        $simple = fn (Kind $kind, string $one, ?string $many = null) => [
            'key' => $kind->value, 'title' => $kind->title(), 'need' => $kind->need(),
            'ok' => ($n = $count($kind)->count()) > 0, 'detail' => $n > 0 ? $plural($n, $one, $many) : 'None yet',
        ];

        return [
            ['key' => 'logo', 'title' => 'Logos', 'need' => Need::Required,
                'ok' => $logos->contains(fn (BrandKitItem $l) => $l->value('variant') === 'full'),
                'detail' => $logos->isEmpty() ? 'None yet' : ($logos->contains(fn (BrandKitItem $l) => $l->value('variant') === 'full') ? $plural($logos->count(), 'logo') : 'Only the mark: the full logo is needed')],
            ['key' => 'colour', 'title' => 'Colours', 'need' => Need::Required,
                'ok' => $colours->contains(fn (BrandKitItem $c) => $c->value('role') === 'primary'),
                'detail' => $colours->isEmpty() ? 'None yet' : ($colours->contains(fn (BrandKitItem $c) => $c->value('role') === 'primary') ? $plural($colours->count(), 'colour') : 'No primary colour yet')],
            ['key' => 'font', 'title' => 'Fonts', 'need' => Need::Required,
                'ok' => $fontOk('headline') && $fontOk('body'),
                'detail' => $fontOk('headline') && $fontOk('body') ? 'Headline and body, licensed' : (($fontOk('headline') || $fontOk('body')) ? 'Needs both a headline and a body font, each marked as licensed for ads' : 'A headline font and a body font, licensed for ads')],
            ['key' => 'voice', 'title' => 'Voice', 'need' => Need::Required,
                'ok' => $kit->voice('tone') !== '',
                'detail' => $kit->voice('tone') !== '' ? ($kit->voice('tone', 'fr') !== '' ? 'English and French' : 'English') : 'A few sentences on tone'],
            ['key' => 'direction', 'title' => 'Visual direction', 'need' => Need::Required,
                'ok' => $kit->direction('mood') !== '',
                'detail' => $kit->direction('mood') !== '' ? count(array_filter(array_map(fn ($p) => $kit->direction($p), BrandKit::DIRECTION))).' of '.count(BrandKit::DIRECTION).' parts' : 'At least the mood'],
            ['key' => 'reference', 'title' => 'Style references', 'need' => Need::Required,
                'ok' => $references >= $min,
                'detail' => $min > 0 ? "{$references} of {$min}" : $plural($references, 'image')],
            $simple(Kind::BestAd, 'ad'),
            $simple(Kind::BadExample, 'example'),
            $simple(Kind::Cta, 'call to action', 'calls to action'),
            $simple(Kind::LegalLine, 'legal line'),
            $simple(Kind::PackShot, 'pack shot'),
            $simple(Kind::Music, 'track'),
        ];
    }

    /**
     * Whether everything the brief marks required is there.
     */
    public function isReady(Brand $brand): bool
    {
        return collect($this->readiness($brand))->every(fn (array $check) => $check['need'] !== Need::Required || $check['ok']);
    }

    /**
     * What's missing, in words, for a message.
     *
     * @return list<string>
     */
    public function missing(Brand $brand): array
    {
        return collect($this->readiness($brand))->filter(fn (array $c) => $c['need'] === Need::Required && ! $c['ok'])->pluck('title')->all();
    }

    /**
     * The kit as text for the prompts: colours, fonts, voice, direction,
     * calls to action and legal lines. Files aren't listed; the planner is
     * given those separately.
     */
    public function summary(Brand $brand, string $lang = 'en'): string
    {
        $kit = $this->for($brand);
        $lines = ["Brand: {$brand->name}"];
        $text = fn (BrandKitItem $i) => trim((string) ($i->value('text')[$lang] ?? '')) ?: trim((string) ($i->value('text')['en'] ?? ''));

        if (($colours = $this->items($brand, Kind::Colour))->isNotEmpty()) {
            $lines[] = 'Colours: '.$colours->map(fn (BrandKitItem $c) => "{$c->value('name')} {$c->value('hex')} ({$c->value('role')}, ".Kind::Colour->field('use')->optionsFor($brand)[$c->value('use')].')')->implode('; ');
        }
        if (($fonts = $this->items($brand, Kind::Font))->isNotEmpty()) {
            $lines[] = 'Fonts: '.$fonts->map(fn (BrandKitItem $f) => ucfirst((string) $f->value('role')).': '.$f->value('family'))->implode('; ');
        }
        foreach (['tone' => 'Voice', 'use' => 'Words and phrases to use', 'avoid' => 'Words and phrases to avoid'] as $part => $label) {
            if (($value = $kit->voice($part, $lang) ?: $kit->voice($part)) !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }
        $direction = [];
        foreach (['mood' => 'Mood', 'lighting' => 'Lighting', 'settings' => 'Settings', 'people' => 'People and activities', 'avoid' => 'Avoid'] as $part => $label) {
            if ($kit->direction($part) !== '') {
                $direction[] = "{$label}: {$kit->direction($part)}";
            }
        }
        if ($direction !== []) {
            $lines[] = "Visual direction:\n- ".implode("\n- ", $direction);
        }
        if (($ctas = $this->items($brand, Kind::Cta)->map($text)->filter())->isNotEmpty()) {
            $lines[] = 'Calls to action: '.$ctas->implode('; ');
        }
        if (($legal = $this->items($brand, Kind::LegalLine))->isNotEmpty()) {
            $lines[] = "Legal lines:\n- ".$legal->map(fn (BrandKitItem $l) => $text($l).($l->value('when') ? " (when: {$l->value('when')})" : ''))->implode("\n- ");
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function variantId(Brand $brand, array $data): ?int
    {
        $id = (int) ($data['product_variant_id'] ?? 0);

        return $id && ProductVariant::whereKey($id)->whereHas('product', fn ($q) => $q->where('brand_id', $brand->id))->exists() ? $id : null;
    }
}
