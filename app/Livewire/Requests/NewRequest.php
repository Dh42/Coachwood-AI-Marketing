<?php

namespace App\Livewire\Requests;

use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Requests\Changes;
use App\Requests\RequestReferences;
use App\Requests\Requests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Ask for creative: the brief in plain words, the placements, what kind
 * of output, and the optional product, reference images and model.
 */
#[Title('New request')]
class NewRequest extends Component
{
    use RequestForm, WithFileUploads;

    #[Locked]
    public Brand $brand;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var list<string> A kind per upload: style or product. */
    public array $uploadKinds = [];

    public function mount(Brand $brand, Changes $changes): void
    {
        $this->authorize('requests.create', $brand);
        $this->brand = $brand;
        $this->form = $this->blankForm($brand);

        // "Start a fresh request" from an image that has had its changes: the same choices, the brief with the changes written in.
        $from = request()->integer('from') ? CreativeRequest::where('brand_id', $brand->id)->find(request()->integer('from')) : null;
        if ($from && Auth::user()->can('view', $from)) {
            $this->form = [...$this->formFrom($from), 'public' => $from->public];
            $output = request()->integer('output') ? $from->outputs()->find(request()->integer('output')) : null;
            $this->form['brief'] = $output ? $changes->foldedBrief($output) : $from->brief;
        }
    }

    public function updatedUploads(): void
    {
        $this->resetErrorBag('uploads');
        foreach ($this->uploads as $i => $file) {
            $this->uploadKinds[$i] ??= 'style';
        }
    }

    public function dropUpload(int $index): void
    {
        array_splice($this->uploads, $index, 1);
        array_splice($this->uploadKinds, $index, 1);
    }

    public function save(Requests $requests, RequestReferences $references)
    {
        $this->authorize('requests.create', $this->brand);
        $this->resetErrorBag();

        try {
            $request = $requests->create($this->brand, $this->form, Auth::user());

            foreach ($this->uploads as $i => $file) {
                $references->add($request, $file, $this->uploadKinds[$i] ?? 'style');
            }
        } catch (ValidationException $e) {
            if (isset($request)) {
                $request->delete();
            }
            $this->formErrors($e);
            if (isset($e->errors()['file'])) {
                $this->addError('uploads', $e->errors()['file'][0]);
            }

            return;
        }

        $requests->plan($request);

        return $this->redirectRoute('requests.show', [$this->brand, $request]);
    }

    public function render()
    {
        return view('livewire.requests.new-request', $this->formData($this->brand));
    }
}
