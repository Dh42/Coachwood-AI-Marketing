<?php

namespace App\Livewire\Models;

use App\Images\ImageGenerator;
use App\Images\ImageModels;
use App\Images\ImageSize;
use App\Models\ImageModel;
use App\Models\LlmModel;
use App\Plugins\Connections;
use App\Plugins\PluginRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The model list: the image models each provider offers and the text
 * models that plan, write and review. Admins add rows, switch them on and
 * off and keep the prices current; the planner reads what's on.
 */
#[Title('Models')]
class ModelsPage extends Component
{
    /** 'new' or an image model id, while its form is open. */
    public ?string $imageOpen = null;

    /** @var array<string, mixed> */
    public array $imageForm = [];

    /** A text model id, while its form is open. */
    public ?string $llmOpen = null;

    /** @var array<string, mixed> */
    public array $llmForm = [];

    /** The image model id waiting for "Yes, remove". */
    public ?int $removing = null;

    public function mount(): void
    {
        $this->authorize('models.manage');
    }

    public function addStarters(ImageModels $models): void
    {
        $this->authorize('models.manage');
        $added = $models->addStarters(Auth::user());
        $this->dispatch('saved', message: $added > 0 ? "Added {$added} image models." : 'Every starting model is already here.');
    }

    public function openImage(?int $id = null, ?string $provider = null): void
    {
        $this->closeAll();
        $model = $id ? ImageModel::findOrFail($id) : null;
        $this->imageOpen = $model ? (string) $model->id : 'new';
        $sizes = $model?->sizes ?? ['1K'];
        $qualities = $model?->qualities ?? [];
        $this->imageForm = [
            'provider' => $model?->provider ?? $provider ?? '',
            'model' => $model?->model ?? '',
            'label' => $model?->label ?? '',
            'aspect_ratios' => $model?->aspect_ratios ?? ImageModels::RATIOS,
            'sizes' => $sizes,
            'qualities' => implode(', ', $qualities),
            'max_references' => $model?->max_references ?? 0,
            'edits' => $model?->edits ?? false,
            'transparent' => $model?->transparent ?? false,
            'renders_text' => $model?->renders_text ?? false,
            'prices' => $model?->prices ?? [],
            'input_price' => $model?->input_price ?? '',
            'image_input_price' => $model?->image_input_price ?? '',
            'output_price' => $model?->output_price ?? '',
            'notes' => $model?->notes ?? '',
        ];
    }

    public function saveImage(ImageModels $models): void
    {
        $this->authorize('models.manage');
        $model = $this->imageOpen && $this->imageOpen !== 'new' ? ImageModel::findOrFail((int) $this->imageOpen) : null;

        try {
            $saved = $models->save($model, $this->imageForm, Auth::user());
        } catch (ValidationException $e) {
            $this->errorsUnder('imageForm', $e);

            return;
        }

        $this->closeAll();
        $this->dispatch('saved', message: "Saved {$saved->label}.");
    }

    public function toggleImage(int $id, ImageModels $models): void
    {
        $this->authorize('models.manage');
        $model = ImageModel::findOrFail($id);
        $models->enable($model, ! $model->enabled, Auth::user());
    }

    public function askRemove(int $id): void
    {
        $this->removing = $id;
    }

    public function keep(): void
    {
        $this->removing = null;
    }

    public function remove(int $id, ImageModels $models): void
    {
        $this->authorize('models.manage');
        $model = ImageModel::findOrFail($id);
        $models->delete($model, Auth::user());
        $this->removing = null;
        $this->dispatch('saved', message: "Removed {$model->label}.");
    }

    public function openLlm(int $id): void
    {
        $this->closeAll();
        $model = LlmModel::findOrFail($id);
        $this->llmOpen = (string) $model->id;
        $this->llmForm = collect($model->only(['label', 'input_price', 'output_price', 'cache_write_price', 'cache_read_price']))->map(fn ($v) => (string) $v)->all();
    }

    public function saveLlm(ImageModels $models): void
    {
        $this->authorize('models.manage');
        $model = LlmModel::findOrFail((int) $this->llmOpen);

        try {
            $models->saveLlmModel($model, $this->llmForm, Auth::user());
        } catch (ValidationException $e) {
            $this->errorsUnder('llmForm', $e);

            return;
        }

        $this->closeAll();
        $this->dispatch('saved', message: "Saved {$model->fresh()->label}.");
    }

    public function toggleLlm(int $id, ImageModels $models): void
    {
        $this->authorize('models.manage');
        $model = LlmModel::findOrFail($id);
        $models->enableLlmModel($model, ! $model->enabled, Auth::user());
    }

    public function closeAll(): void
    {
        $this->imageOpen = null;
        $this->llmOpen = null;
        $this->removing = null;
        $this->resetErrorBag();
    }

    private function errorsUnder(string $prefix, ValidationException $e): void
    {
        foreach ($e->errors() as $key => $messages) {
            foreach ($messages as $message) {
                $this->addError("{$prefix}.{$key}", $message);
            }
        }
    }

    public function render(ImageModels $models, PluginRegistry $plugins, Connections $connections, ImageGenerator $generator)
    {
        $providers = $models->providers()->map(fn ($plugin, $key) => [
            'key' => $key,
            'name' => $plugin->name(),
            'enabled' => $plugins->isEnabled($key),
            'connection' => $connections->for($key),
            'models' => ImageModel::where('provider', $key)->orderBy('position')->orderBy('label')->get(),
        ]);

        $llmProviders = $plugins->all()->mapWithKeys(fn ($p, $key) => [$key => $p->name()])->all();

        return view('livewire.models.models-page', [
            'providers' => $providers,
            'llmModels' => LlmModel::orderBy('position')->get(),
            'llmProviders' => $llmProviders,
            'starters' => $models->providers()->sum(fn ($p) => count($p->imageModels())),
            'sizes' => ImageSize::OPTIONS,
            'ratios' => ImageModels::RATIOS,
            'priceKeys' => ImageModels::priceKeys(
                array_values(array_intersect(array_keys(ImageSize::OPTIONS), (array) ($this->imageForm['sizes'] ?? []))),
                collect(explode(',', (string) ($this->imageForm['qualities'] ?? '')))->map(fn ($q) => strtolower(trim($q)))->filter()->unique()->values()->all(),
            ),
        ]);
    }
}
