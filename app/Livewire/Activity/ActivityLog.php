<?php

namespace App\Livewire\Activity;

use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;

/**
 * Everything the audit trail recorded: sign-ins, role changes, settings,
 * brand changes. Filters live in the URL so a filtered view can be shared.
 */
#[Title('Activity log')]
class ActivityLog extends Component
{
    use WithPagination;

    /** Human names for each log. Logs added later by plugins show their own name. */
    public const CATEGORIES = [
        'auth' => 'Sign-in',
        'users' => 'Users and roles',
        'brands' => 'Brands',
        'settings' => 'Settings',
        'connections' => 'Connections',
        'scheduler' => 'Scheduled tasks',
    ];

    #[Url]
    public string $category = '';

    #[Url]
    public string $person = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $search = '';

    public function updating(string $property): void
    {
        if (in_array($property, ['category', 'person', 'from', 'to', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('category', 'person', 'from', 'to', 'search');
        $this->resetPage();
    }

    public static function categoryLabel(?string $logName): string
    {
        return self::CATEGORIES[$logName] ?? ucfirst((string) $logName);
    }

    public function render()
    {
        $this->authorize('activity.view');

        $tz = app(Settings::class)->get('platform.timezone');

        $entries = Activity::query()
            ->with('causer')
            ->when($this->category !== '', fn ($q) => $q->where('log_name', $this->category))
            ->when($this->person !== '', fn ($q) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $this->person))
            ->when($this->from !== '', fn ($q) => $q->where('created_at', '>=', Carbon::parse($this->from, $tz)->startOfDay()->utc()))
            ->when($this->to !== '', fn ($q) => $q->where('created_at', '<=', Carbon::parse($this->to, $tz)->endOfDay()->utc()))
            ->when(trim($this->search) !== '', fn ($q) => $q->whereLike('description', '%'.trim($this->search).'%'))
            ->latest('id')
            ->paginate(50);

        return view('livewire.activity.activity-log', [
            'entries' => $entries,
            'tz' => $tz,
            'categories' => collect(self::CATEGORIES)
                ->union(Activity::query()->distinct()->pluck('log_name')->filter()->mapWithKeys(fn ($n) => [$n => self::categoryLabel($n)]))
                ->all(),
            'people' => User::whereIn('id', Activity::query()->where('causer_type', (new User)->getMorphClass())->distinct()->pluck('causer_id'))
                ->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
