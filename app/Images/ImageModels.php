<?php

namespace App\Images;

use App\Models\ImageModel;
use App\Models\LlmModel;
use App\Models\User;
use App\Platforms\AspectRatio;
use App\Plugins\Contracts\ImageProvider;
use App\Plugins\PluginRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The model list: the image models each provider plugin offers and the
 * text models that plan, write and review. Admins add rows, switch them on
 * and off and keep the prices current; the planner reads what's on.
 */
class ImageModels
{
    /** The shapes a model row may list: the common ratios placements are generated at. */
    public const RATIOS = ['1:1', '4:5', '5:4', '9:16', '16:9', '1.91:1', '2:3', '3:2', '4:3', '3:4', '2:1', '1:2', '21:9'];

    public function __construct(private PluginRegistry $plugins) {}

    /**
     * The installed plugins that make images, on or off.
     *
     * @return Collection<string, ImageProvider>
     */
    public function providers(): Collection
    {
        return $this->plugins->all()->filter(fn ($p) => $p instanceof ImageProvider);
    }

    /**
     * @return Collection<int, ImageModel>
     */
    public function all(): Collection
    {
        return ImageModel::orderBy('provider')->orderBy('position')->orderBy('label')->get();
    }

    /**
     * Models that can be used now: switched on, on a plugin that's switched on.
     *
     * @return Collection<int, ImageModel>
     */
    public function available(): Collection
    {
        return $this->all()->filter(fn (ImageModel $m) => $m->enabled && $this->plugins->isEnabled($m->provider))->values();
    }

    /**
     * Adds each provider's starting models that aren't here yet (by
     * provider and model ID), leaving edited rows alone. Returns how many
     * were added.
     */
    public function addStarters(User $by): int
    {
        $added = 0;

        foreach ($this->providers() as $key => $provider) {
            $have = ImageModel::where('provider', $key)->pluck('model')->all();

            foreach ($provider->imageModels() as $row) {
                if (! in_array($row['model'], $have, true)) {
                    ImageModel::create([...$row, 'provider' => $key]);
                    $added++;
                }
            }
        }

        if ($added > 0) {
            activity('models')->causedBy($by)->event('models_added')->log("Added the starting image models ({$added})");
        }

        return $added;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function save(?ImageModel $model, array $input, User $by): ImageModel
    {
        $providers = $this->providers()->keys()->all();

        $data = Validator::make($input, [
            'provider' => ['required', Rule::in($providers)],
            'model' => ['required', 'string', 'max:100', 'regex:~^[a-z0-9][a-z0-9._\-]*(/[a-z0-9][a-z0-9._\-]*)*$~i',
                Rule::unique('image_models', 'model')->where('provider', $input['provider'] ?? '')->ignore($model?->id)],
            'label' => ['required', 'string', 'max:100'],
            'aspect_ratios' => ['required', 'array', 'min:1'],
            'aspect_ratios.*' => [Rule::in(self::RATIOS)],
            'sizes' => ['required', 'array', 'min:1'],
            'sizes.*' => [Rule::in(array_keys(ImageSize::OPTIONS))],
            'qualities' => ['nullable', 'string', 'max:200'],
            'max_references' => ['nullable', 'integer', 'min:0', 'max:100'],
            'edits' => ['boolean'],
            'transparent' => ['boolean'],
            'renders_text' => ['boolean'],
            'prices' => ['array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'input_price' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'image_input_price' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'output_price' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'provider.in' => 'Choose one of the installed providers.',
            'model.regex' => 'The model ID is the provider\'s own, such as gemini-nano-banana-2.1 or ideogram/v4.0.',
            'model.unique' => 'This provider already has that model.',
            'aspect_ratios.required' => 'Tick at least one shape.',
            'aspect_ratios.min' => 'Tick at least one shape.',
            'sizes.required' => 'Tick at least one size.',
            'sizes.min' => 'Tick at least one size.',
        ], ['model' => 'model ID', 'label' => 'name'])->validate();

        $qualities = collect(explode(',', (string) ($data['qualities'] ?? '')))->map(fn ($q) => strtolower(trim($q)))->filter()->unique()->values()->all();
        $sizes = array_values(array_intersect(array_keys(ImageSize::OPTIONS), $data['sizes']));
        $prices = collect($data['prices'] ?? [])->filter(fn ($p) => $p !== null && $p !== '')->map(fn ($p) => round((float) $p, 4))->all();

        $values = [
            'provider' => $data['provider'], 'model' => $data['model'], 'label' => trim($data['label']),
            'aspect_ratios' => array_values(array_intersect(self::RATIOS, $data['aspect_ratios'])),
            'sizes' => $sizes, 'qualities' => $qualities,
            'max_references' => (int) ($data['max_references'] ?? 0),
            'edits' => (bool) ($data['edits'] ?? false) && (int) ($data['max_references'] ?? 0) > 0,
            'transparent' => (bool) ($data['transparent'] ?? false), 'renders_text' => (bool) ($data['renders_text'] ?? false),
            'prices' => $prices,
            'input_price' => (float) ($data['input_price'] ?? 0), 'image_input_price' => (float) ($data['image_input_price'] ?? 0), 'output_price' => (float) ($data['output_price'] ?? 0),
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];

        if ($model) {
            $model->update($values);
            activity('models')->causedBy($by)->performedOn($model)->event('model_updated')->log("Changed the image model \"{$model->label}\"");
        } else {
            $model = ImageModel::create([...$values, 'position' => ((int) ImageModel::where('provider', $values['provider'])->max('position')) + 10]);
            activity('models')->causedBy($by)->performedOn($model)->event('model_added')->log("Added the image model \"{$model->label}\"");
        }

        return $model;
    }

    public function enable(ImageModel $model, bool $enabled, User $by): void
    {
        $model->update(['enabled' => $enabled]);
        activity('models')->causedBy($by)->performedOn($model)->event($enabled ? 'model_enabled' : 'model_disabled')->log(($enabled ? 'Switched on' : 'Switched off')." the image model \"{$model->label}\"");
    }

    public function delete(ImageModel $model, User $by): void
    {
        $model->delete();
        activity('models')->causedBy($by)->event('model_removed')->withProperties(['model' => $model->key()])->log("Removed the image model \"{$model->label}\"");
    }

    /**
     * Price keys a row's table should have, from its sizes and qualities.
     *
     * @param  list<string>  $sizes
     * @param  list<string>  $qualities
     * @return list<string>
     */
    public static function priceKeys(array $sizes, array $qualities): array
    {
        $keys = [];
        foreach ($qualities ?: [null] as $quality) {
            foreach ($sizes as $size) {
                $keys[] = $quality ? "{$quality} {$size}" : $size;
            }
        }

        return $keys;
    }

    /**
     * Text models: on or off, and their prices per million tokens.
     *
     * @param  array<string, mixed>  $input
     */
    public function saveLlmModel(LlmModel $model, array $input, User $by): void
    {
        $data = Validator::make($input, [
            'label' => ['required', 'string', 'max:100'],
            'input_price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'output_price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'cache_write_price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'cache_read_price' => ['required', 'numeric', 'min:0', 'max:10000'],
        ], [], ['label' => 'name'])->validate();

        $model->update([...$data, 'label' => trim($data['label'])]);
        activity('models')->causedBy($by)->event('model_updated')->withProperties(['model' => $model->model])->log("Changed the text model \"{$model->label}\"");
    }

    public function enableLlmModel(LlmModel $model, bool $enabled, User $by): void
    {
        $model->update(['enabled' => $enabled]);
        activity('models')->causedBy($by)->event($enabled ? 'model_enabled' : 'model_disabled')->withProperties(['model' => $model->model])->log(($enabled ? 'Switched on' : 'Switched off')." the text model \"{$model->label}\"");
    }

    /**
     * Checks a request against a model before anything is sent, and
     * returns the ratio to generate at.
     *
     * @throws ValidationException
     */
    public function check(ImageModel $model, ImageRequest $request): string
    {
        $errors = [];

        $ratio = $model->closestRatio($request->aspectRatio);
        if ($ratio === null) {
            $errors['aspect_ratio'] = "{$model->label} doesn't make {$request->aspectRatio} images, or anything near that shape.";
        }
        if (! $model->takesSize($request->size)) {
            $errors['size'] = "{$model->label} doesn't make {$request->size} images. It makes ".implode(', ', $model->sizes ?? []).'.';
        }
        if (! $model->takesQuality($request->quality)) {
            $errors['quality'] = "{$model->label} has no \"{$request->quality}\" quality.".($model->hasQualities() ? ' It has '.implode(', ', $model->qualities).'.' : '');
        }
        if (count($request->references) > 0 && ! $model->edits) {
            $errors['references'] = "{$model->label} doesn't take reference images.";
        } elseif (count($request->references) > $model->max_references) {
            $errors['references'] = "{$model->label} takes up to {$model->max_references} reference images; this request has ".count($request->references).'.';
        }
        if ($request->transparent && ! $model->transparent) {
            $errors['transparent'] = "{$model->label} can't make transparent backgrounds.";
        }
        if ($request->count < 1 || $request->count > 10) {
            $errors['count'] = 'Ask for between 1 and 10 images at a time.';
        }
        if (! AspectRatio::value($request->aspectRatio)) {
            $errors['aspect_ratio'] = 'The shape must be a ratio such as 4:5.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $ratio;
    }
}
