<?php

namespace App\Livewire\Prompts;

use App\Models\Brand;
use App\Models\PromptRevision;
use App\Prompts\PromptAssistant;
use App\Prompts\PromptDiff;
use App\Prompts\Prompts;
use App\Prompts\PromptTrials;
use App\Prompts\PromptType;
use App\Settings\Settings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * The brand's prompts: edit each by hand or by describing the problem,
 * try the edited text on a sample before saving, keep your own additions,
 * read the history and roll back.
 */
#[Title('Prompts')]
class PromptsPage extends Component
{
    #[Locked]
    public Brand $brand;

    #[Url]
    public string $type = 'planner';

    public string $body = '';

    public string $note = '';

    /** @var list<array{key: string, label: string, weight: int|string, what: string}> */
    public array $criteria = [];

    /** @var list<array{key: string, label: string}> */
    public array $hardFails = [];

    public string $problem = '';

    /** The AI's draft while it's being looked at: body, settings, explanation, request. */
    public ?array $draft = null;

    /** The problem described that led to the text in the editor, so the save records it. */
    public ?string $draftRequest = null;

    public string $addition = '';

    public bool $showHistory = false;

    /** The revision whose comparison with the one before is open. */
    public ?int $compare = null;

    public ?int $restoring = null;

    /** The sample a try runs on: a brief (planner, copy), a scene (images, changes) and a change (changes). */
    public string $sampleBrief = '';

    public string $sampleScene = '';

    public string $sampleChange = '';

    /** The try under way or just finished: its token, and what the cache says about it. */
    public ?string $trial = null;

    public ?array $trialResult = null;

    public function mount(Brand $brand): void
    {
        $this->brand = $brand;
        if (! PromptType::tryFrom($this->type)) {
            $this->type = PromptType::Planner->value;
        }
        $this->load();
        $this->loadSamples();
    }

    public function pick(string $type): void
    {
        if (! PromptType::tryFrom($type)) {
            return;
        }
        $this->type = $type;
        $this->draft = null;
        $this->draftRequest = null;
        $this->problem = '';
        $this->note = '';
        $this->compare = null;
        $this->restoring = null;
        $this->trial = null;
        $this->trialResult = null;
        $this->resetErrorBag();
        $this->load();
    }

    /**
     * Runs the text in the editor, unsaved, on the sample: the result lands below it.
     */
    public function tryIt(PromptTrials $trials): void
    {
        $this->authorize('prompts.edit', $this->brand);
        $this->resetErrorBag();
        $type = $this->promptType();
        $samples = ['brief' => trim($this->sampleBrief), 'scene' => trim($this->sampleScene), 'change' => trim($this->sampleChange)];

        if (in_array($type, [PromptType::Planner, PromptType::Copy], true) && (mb_strlen($samples['brief']) < 10 || mb_strlen($samples['brief']) > 2000)) {
            $this->addError('sampleBrief', 'Type a sample brief of a sentence or two.');

            return;
        }
        if (in_array($type, [PromptType::Image, PromptType::Change], true) && (mb_strlen($samples['scene']) < 10 || mb_strlen($samples['scene']) > 2000)) {
            $this->addError('sampleScene', 'Describe the sample scene in a sentence or two.');

            return;
        }
        if ($type === PromptType::Change && (mb_strlen($samples['change']) < 3 || mb_strlen($samples['change']) > 500)) {
            $this->addError('sampleChange', 'Say what change to try, in a few words.');

            return;
        }

        try {
            $this->trial = $trials->start($this->brand, $type, Auth::user(), $this->body, $this->settingsFromForm(), $samples);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        } catch (RuntimeException $e) {
            $this->addError('try', $e->getMessage());

            return;
        }

        $this->trialResult = $trials->result($this->trial, $this->brand);
    }

    public function pollTrial(PromptTrials $trials): void
    {
        if ($this->trial) {
            $this->trialResult = $trials->result($this->trial, $this->brand) ?? ['status' => 'failed', 'error' => 'The try was lost before it finished. Try again.'];
        }
    }

    public function dismissTrial(): void
    {
        $this->trial = null;
        $this->trialResult = null;
    }

    public function save(Prompts $prompts): void
    {
        $this->authorize('prompts.edit', $this->brand);
        $this->resetErrorBag();
        $prompt = $prompts->for($this->brand, $this->promptType());

        try {
            $revision = $prompts->save($prompt, $this->body, $this->settingsFromForm(), $this->note, $this->draftRequest ? PromptRevision::AI : PromptRevision::HAND, $this->draftRequest, Auth::user());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $key => $messages) {
                $this->addError($key, $messages[0]);
            }

            return;
        }

        $this->note = '';
        $this->draftRequest = null;
        $this->load();
        $this->dispatch('saved', message: "Saved as v{$revision->number}.");
    }

    public function askAi(PromptAssistant $assistant, Prompts $prompts): void
    {
        $this->authorize('prompts.edit', $this->brand);
        $this->resetErrorBag();
        $problem = trim($this->problem);
        if (mb_strlen($problem) < 10) {
            $this->addError('problem', 'Say what goes wrong, in a sentence or two.');

            return;
        }

        try {
            $draft = $assistant->draft($prompts->for($this->brand, $this->promptType()), $problem, Auth::user());
        } catch (RuntimeException $e) {
            $this->addError('problem', $e->getMessage());

            return;
        }

        $this->draft = [...$draft, 'request' => $problem];
    }

    public function acceptDraft(Prompts $prompts): void
    {
        $this->authorize('prompts.edit', $this->brand);
        if (! $this->draft) {
            return;
        }
        $this->resetErrorBag();

        try {
            $revision = $prompts->save($prompts->for($this->brand, $this->promptType()), $this->draft['body'], $this->draft['settings'], $this->note, PromptRevision::AI, $this->draft['request'], Auth::user());
        } catch (ValidationException $e) {
            $this->addError('problem', collect($e->errors())->flatten()->first());

            return;
        }

        $this->draft = null;
        $this->problem = '';
        $this->note = '';
        $this->load();
        $this->dispatch('saved', message: "Saved the AI's change as v{$revision->number}.");
    }

    public function adjustDraft(): void
    {
        if (! $this->draft) {
            return;
        }
        $this->body = $this->draft['body'];
        if ($this->draft['settings']) {
            $this->criteria = $this->draft['settings']['criteria'];
            $this->hardFails = $this->draft['settings']['hard_fails'];
        }
        $this->draftRequest = $this->draft['request'];
        $this->draft = null;
    }

    public function discardDraft(): void
    {
        $this->draft = null;
    }

    public function saveAddition(Prompts $prompts): void
    {
        $this->authorize('prompts.personal', $this->brand);
        $this->resetErrorBag();

        try {
            $prompts->saveAddition($this->brand, $this->promptType(), Auth::user(), $this->addition);
        } catch (ValidationException $e) {
            $this->addError('addition', collect($e->errors())->flatten()->first());

            return;
        }

        $this->dispatch('saved', message: trim($this->addition) === '' ? 'Your additions are removed.' : 'Your additions are saved.');
    }

    public function addCriterion(): void
    {
        if (count($this->criteria) < Prompts::MAX_CRITERIA) {
            $this->criteria[] = ['key' => '', 'label' => '', 'weight' => 10, 'what' => ''];
        }
    }

    public function removeCriterion(int $index): void
    {
        unset($this->criteria[$index]);
        $this->criteria = array_values($this->criteria);
    }

    public function addHardFail(): void
    {
        if (count($this->hardFails) < Prompts::MAX_HARD_FAILS) {
            $this->hardFails[] = ['key' => '', 'label' => ''];
        }
    }

    public function removeHardFail(int $index): void
    {
        unset($this->hardFails[$index]);
        $this->hardFails = array_values($this->hardFails);
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
        $this->compare = null;
        $this->restoring = null;
    }

    public function showDiff(?int $revisionId): void
    {
        $this->compare = $this->compare === $revisionId ? null : $revisionId;
    }

    public function askRestore(int $revisionId): void
    {
        $this->restoring = $revisionId;
    }

    public function keep(): void
    {
        $this->restoring = null;
    }

    public function restore(int $revisionId, Prompts $prompts): void
    {
        $this->authorize('prompts.edit', $this->brand);
        $this->resetErrorBag();
        $prompt = $prompts->for($this->brand, $this->promptType());
        $to = $prompt->revisions()->findOrFail($revisionId);

        try {
            $revision = $prompts->rollBack($prompt, $to, Auth::user());
        } catch (ValidationException $e) {
            $this->addError('body', collect($e->errors())->flatten()->first());

            return;
        }

        $this->restoring = null;
        $this->compare = null;
        $this->draftRequest = null;
        $this->load();
        $this->dispatch('saved', message: "v{$to->number} is current again, saved as v{$revision->number}.");
    }

    public function render(Prompts $prompts, PromptTrials $trials)
    {
        $user = Auth::user();
        $type = $this->promptType();
        $prompt = $prompts->for($this->brand, $type);
        $canEdit = $user->hasPermission('prompts.edit', $this->brand->id);
        $history = $this->showHistory ? $prompts->history($prompt) : collect();

        $compareRows = null;
        if ($this->compare && ($rev = $history->firstWhere('id', $this->compare))) {
            $before = $history->firstWhere('number', $rev->number - 1);
            $compareRows = PromptDiff::sideBySide($before?->asText() ?? '', $rev->asText());
        }

        return view('livewire.prompts.prompts-page', [
            'types' => collect(PromptType::cases())->map(fn (PromptType $t) => ['value' => $t->value, 'label' => $t->label()]),
            'promptType' => $type,
            'prompt' => $prompt,
            'current' => $prompt->current,
            'canEdit' => $canEdit,
            'canAdd' => $user->hasPermission('prompts.personal', $this->brand->id),
            'missing' => $canEdit ? Prompts::missingPlaceholders($type, $this->body) : [],
            'draftRows' => $this->draft ? PromptDiff::sideBySide($prompt->current->asText(), (new PromptRevision(['body' => $this->draft['body'], 'settings' => $this->draft['settings']]))->asText()) : null,
            'history' => $history,
            'compareRows' => $compareRows,
            'others' => $user->isAdmin() ? $prompts->additions($this->brand, $type)->where('user_id', '!=', $user->id)->values() : collect(),
            'trials' => $trials,
            'trialInfo' => $canEdit ? $trials->describe($this->brand, $type) : null,
            'tryBudget' => $canEdit ? $trials->budget($user) : null,
            'tz' => app(Settings::class)->get('platform.timezone'),
        ]);
    }

    private function promptType(): PromptType
    {
        return PromptType::from($this->type);
    }

    private function load(): void
    {
        $prompts = app(Prompts::class);
        $prompt = $prompts->for($this->brand, $this->promptType());
        $this->body = $prompt->body();
        $this->criteria = $prompt->current->criteria();
        $this->hardFails = $prompt->current->hardFails();
        $this->addition = $prompts->addition($this->brand, $this->promptType(), Auth::user())?->body() ?? '';
    }

    private function loadSamples(): void
    {
        $samples = app(PromptTrials::class)->samples($this->brand);
        $this->sampleBrief = $samples['brief'];
        $this->sampleScene = $samples['scene'];
        $this->sampleChange = $samples['change'];
    }

    /**
     * @return array{criteria: list<array<string, mixed>>, hard_fails: list<array<string, mixed>>}|null
     */
    private function settingsFromForm(): ?array
    {
        return $this->promptType()->hasCriteria() ? ['criteria' => $this->criteria, 'hard_fails' => $this->hardFails] : null;
    }
}
