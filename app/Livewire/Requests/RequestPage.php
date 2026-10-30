<?php

namespace App\Livewire\Requests;

use App\Budgets\Budgets;
use App\Models\Brand;
use App\Models\CreativeRequest;
use App\Models\Output;
use App\Notices\Notices;
use App\Prompts\Prompts;
use App\Requests\Changes;
use App\Requests\Requests;
use App\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One request from brief to plan: the planner's questions and the answers,
 * the plan with its estimate against the budgets, and confirming it.
 */
class RequestPage extends Component
{
    use RequestForm;

    #[Locked]
    public Brand $brand;

    #[Locked]
    public CreativeRequest $request;

    /** @var array<string, string> Answers being given, by question id. */
    public array $answers = [];

    /** @var array<string, string> "Something else" typed per question. */
    public array $other = [];

    public bool $editing = false;

    /** The image a change is being typed for. */
    public ?int $changing = null;

    public string $instruction = '';

    public function mount(Brand $brand, CreativeRequest $request): void
    {
        abort_unless($request->brand_id === $brand->id, 404);
        $this->authorize('view', $request);
        $this->brand = $brand;
        $this->request = $request;
    }

    public function answer(Requests $requests): void
    {
        $this->authorize('update', $this->request);
        $this->resetErrorBag();

        $answers = $this->answers;
        foreach ($this->other as $id => $text) {
            if (($answers[$id] ?? '') === '__other' || ! isset($answers[$id])) {
                $answers[$id] = trim($text) !== '' ? trim($text) : ($answers[$id] ?? '');
            }
        }

        try {
            $requests->answer($this->request, $answers, Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->answers = [];
        $this->other = [];
        $this->request->refresh();
    }

    public function edit(): void
    {
        $this->authorize('update', $this->request);
        $this->form = $this->formFrom($this->request);
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetErrorBag();
    }

    public function saveChanges(Requests $requests): void
    {
        $this->authorize('update', $this->request);
        $this->resetErrorBag();

        try {
            $requests->change($this->request, $this->form, Auth::user());
        } catch (ValidationException $e) {
            $this->formErrors($e);

            return;
        }

        $this->editing = false;
        $this->request->refresh();
    }

    public function retry(Requests $requests): void
    {
        $this->authorize('update', $this->request);
        $requests->plan($this->request);
        $this->request->refresh();
    }

    public function confirm(Requests $requests): void
    {
        $this->authorize('update', $this->request);

        try {
            $requests->confirm($this->request, Auth::user());
        } catch (ValidationException $e) {
            $this->dispatch('failed', message: collect($e->errors())->flatten()->first());

            return;
        }

        $this->request->refresh();
        $this->dispatch('saved', message: 'Confirmed. The images are on their way.');
    }

    public function cancel(Requests $requests): void
    {
        $this->authorize('update', $this->request);
        $requests->cancel($this->request, Auth::user());
        $this->request->refresh();
    }

    public function setPublic(bool $public, Requests $requests): void
    {
        $this->authorize('update', $this->request);
        $requests->setPublic($this->request, $public, Auth::user());
        $this->request->refresh();
        $this->dispatch('saved', message: $public ? 'Public: the whole team can see it.' : 'Private: only you and admins can see it.');
    }

    public function askChange(int $outputId): void
    {
        $this->authorize('update', $this->request);
        $this->resetErrorBag();
        $this->changing = $this->request->outputs()->where('kind', Output::IMAGE)->findOrFail($outputId)->id;
        $this->instruction = '';
    }

    public function keepImage(): void
    {
        $this->changing = null;
        $this->instruction = '';
        $this->resetErrorBag();
    }

    public function makeChange(Changes $changes): void
    {
        $this->authorize('update', $this->request);
        $this->resetErrorBag();
        $output = $this->request->outputs()->findOrFail($this->changing);

        try {
            $changes->change($output, $this->instruction, Auth::user());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        $this->changing = null;
        $this->instruction = '';
        $this->dispatch('saved', message: 'On its way. The changed image appears under the original when it\'s made and reviewed.');
    }

    public function runAgain(int $generationId, Changes $changes): void
    {
        $this->authorize('update', $this->request);
        $generation = $this->request->generations()->findOrFail($generationId);

        try {
            $changes->again($generation, Auth::user());
        } catch (ValidationException $e) {
            $this->dispatch('failed', message: collect($e->errors())->flatten()->first());

            return;
        }

        $this->dispatch('saved', message: "Running the {$generation->ratio} shape again.");
    }

    public function render(Requests $requests, Budgets $budgets, Prompts $prompts, Changes $changes, Notices $notices)
    {
        $this->request->refresh();
        $user = Auth::user();
        $canUpdate = $user->can('update', $this->request);
        $notices->seen($user, $this->request);

        // Why each image can't be changed right now, if it can't, and where to start afresh once the limit is reached.
        $changeProblems = [];
        if ($canUpdate && $this->request->status === CreativeRequest::DONE) {
            foreach ($this->request->outputs()->where('kind', Output::IMAGE)->get() as $output) {
                $problem = $changes->cannotChange($output);
                $changeProblems[$output->id] = ['problem' => $problem, 'fresh' => $problem && $changes->changesLeft($output) <= 0 ? route('requests.create', [$this->brand, 'from' => $this->request->id, 'output' => $output->id]) : null];
            }
        }

        return view('livewire.requests.request-page', [
            'canUpdate' => $canUpdate,
            'changeProblems' => $changeProblems,
            'rejectsDays' => (int) app(Settings::class)->get('retention.rejects_days', $this->brand->id),
            'rows' => $this->request->plan ? $requests->planRows($this->request) : [],
            'person' => $budgets->person($this->request->user),
            'company' => $budgets->company(),
            'promptsUsed' => $this->request->prompt_revision_id ? $prompts->usedBy($this->request) : [],
            'budgetProblem' => $this->request->status === CreativeRequest::PLANNED ? $budgets->problem($this->request->user, (float) $this->request->estimated_cost) : null,
            ...($this->editing ? $this->formData($this->brand) : []),
        ])->title($this->request->title ?: 'Request');
    }
}
