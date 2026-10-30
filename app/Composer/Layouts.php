<?php

namespace App\Composer;

use App\Models\Brand;
use App\Models\LayoutTemplate;
use App\Models\User;
use App\Platforms\PlatformLibrary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A brand's layout templates: one default per shape, more to choose from
 * later, each a handful of plain choices.
 */
class Layouts
{
    public function __construct(private PlatformLibrary $library) {}

    /**
     * @return Collection<int, LayoutTemplate>
     */
    public function for(Brand $brand): Collection
    {
        return LayoutTemplate::where('brand_id', $brand->id)->orderBy('ratio')->orderByDesc('is_default')->orderBy('position')->orderBy('id')->get();
    }

    /**
     * The template the composer uses for a shape: the brand's default, else
     * any of the brand's for that shape, else the built-in one.
     */
    public function templateFor(Brand $brand, string $ratio): LayoutTemplate
    {
        return LayoutTemplate::where('brand_id', $brand->id)->where('ratio', $ratio)->orderByDesc('is_default')->orderBy('position')->orderBy('id')->first()
            ?? LayoutTemplate::builtIn($brand, $ratio);
    }

    /**
     * The shapes the brand can be asked for: every placement that's on.
     *
     * @return list<string>
     */
    public function ratios(): array
    {
        return $this->library->available()->pluck('ratio')->unique()->sort()->values()->all();
    }

    /**
     * Adds the built-in layout for each shape the brand doesn't have one for yet.
     */
    public function addStarters(Brand $brand, User $by): int
    {
        $have = LayoutTemplate::where('brand_id', $brand->id)->pluck('ratio')->unique()->all();
        $added = 0;

        foreach ($this->ratios() as $ratio) {
            if (! in_array($ratio, $have, true)) {
                LayoutTemplate::create(['brand_id' => $brand->id, 'ratio' => $ratio, 'name' => 'Standard', 'is_default' => true, 'settings' => LayoutTemplate::defaults($ratio), 'position' => 10]);
                $added++;
            }
        }

        if ($added > 0) {
            activity('layouts')->causedBy($by)->performedOn($brand)->event('layouts_added')->log("Added the starting layouts for {$brand->name} ({$added})");
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function save(Brand $brand, ?LayoutTemplate $template, array $input, User $by): LayoutTemplate
    {
        $rules = ['name' => ['required', 'string', 'max:100'], 'ratio' => ['required', 'string', 'max:20', 'regex:/^[\d.]+:[\d.]+$/']];
        foreach (LayoutTemplate::OPTIONS as $key => $options) {
            $rules[$key] = ['required', Rule::in(array_keys($options))];
        }
        foreach (array_keys(LayoutTemplate::TOGGLES) as $key) {
            $rules[$key] = ['boolean'];
        }

        $data = Validator::make($input, $rules, ['ratio.regex' => 'A shape is written like 4:5.'])->validate();

        $settings = [];
        foreach (array_keys(LayoutTemplate::OPTIONS) as $key) {
            $settings[$key] = $data[$key];
        }
        foreach (array_keys(LayoutTemplate::TOGGLES) as $key) {
            $settings[$key] = (bool) ($data[$key] ?? false);
        }

        $values = ['ratio' => $data['ratio'], 'name' => trim($data['name']), 'settings' => $settings];

        if ($template) {
            $template->update($values);
            activity('layouts')->causedBy($by)->performedOn($template)->event('layout_updated')->log("Changed the layout \"{$template->name}\" ({$template->ratio}) for {$brand->name}");
        } else {
            $first = ! LayoutTemplate::where('brand_id', $brand->id)->where('ratio', $data['ratio'])->exists();
            $template = LayoutTemplate::create([...$values, 'brand_id' => $brand->id, 'is_default' => $first, 'position' => 10 + 10 * LayoutTemplate::where('brand_id', $brand->id)->where('ratio', $data['ratio'])->count()]);
            activity('layouts')->causedBy($by)->performedOn($template)->event('layout_added')->log("Added the layout \"{$template->name}\" ({$template->ratio}) for {$brand->name}");
        }

        return $template;
    }

    public function duplicate(LayoutTemplate $template, User $by): LayoutTemplate
    {
        $copy = LayoutTemplate::create(['brand_id' => $template->brand_id, 'ratio' => $template->ratio, 'name' => mb_substr("{$template->name} (copy)", 0, 100), 'is_default' => false,
            'settings' => $template->settings, 'position' => $template->position + 1]);
        activity('layouts')->causedBy($by)->performedOn($copy)->event('layout_added')->log("Copied the layout \"{$template->name}\" ({$template->ratio})");

        return $copy;
    }

    public function makeDefault(LayoutTemplate $template, User $by): void
    {
        LayoutTemplate::where('brand_id', $template->brand_id)->where('ratio', $template->ratio)->update(['is_default' => false]);
        $template->update(['is_default' => true]);
        activity('layouts')->causedBy($by)->performedOn($template)->event('layout_default')->log("Made \"{$template->name}\" the layout for {$template->ratio}");
    }

    public function delete(LayoutTemplate $template, User $by): void
    {
        $template->delete();
        if ($template->is_default) {
            LayoutTemplate::where('brand_id', $template->brand_id)->where('ratio', $template->ratio)->orderBy('position')->first()?->update(['is_default' => true]);
        }
        activity('layouts')->causedBy($by)->event('layout_removed')->withProperties(['ratio' => $template->ratio])->log("Removed the layout \"{$template->name}\" ({$template->ratio})");
    }
}
