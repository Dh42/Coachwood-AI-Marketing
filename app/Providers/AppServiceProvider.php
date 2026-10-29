<?php

namespace App\Providers;

use App\Auth\Permissions;
use App\Auth\Role;
use App\Brands\CurrentBrand;
use App\Catalogue\Catalogue;
use App\Llm\AnthropicProvider;
use App\Llm\Effort;
use App\Llm\LlmManager;
use App\Mail\Transport\GmailApiTransport;
use App\Models\Brand;
use App\Models\Connection;
use App\Models\LlmModel;
use App\Navigation\Navigation;
use App\Navigation\NavItem;
use App\Navigation\NavSection;
use App\Plugins\Connections;
use App\Plugins\Contracts\StoreProvider;
use App\Plugins\CorePluginRegistry;
use App\Plugins\PluginRegistry;
use App\Scheduler\RecordsTaskRuns;
use App\Settings\SettingControl;
use App\Settings\SettingDefinition;
use App\Settings\Settings;
use App\Settings\SettingsArea;
use App\Settings\SettingsAreas;
use App\Settings\SettingScope;
use App\Settings\SettingsRegistry;
use DateTimeZone;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Navigation::class);
        $this->app->singleton(SettingsAreas::class);
        $this->app->singleton(Permissions::class);
        $this->app->singleton(SettingsRegistry::class);
        $this->app->singleton(CorePluginRegistry::class);
        $this->app->singleton(PluginRegistry::class);
        $this->app->singleton(Connections::class);
        $this->app->scoped(CurrentBrand::class);
        $this->app->singleton(LlmManager::class, function () {
            $manager = new LlmManager;
            $manager->register('anthropic', new AnthropicProvider);

            return $manager;
        });

        // Scoped so each request or queued job starts with fresh values.
        $this->app->scoped(Settings::class);
    }

    public function boot(): void
    {
        // {brand} in any URL is a brand, looked up by its ID (slug), even on
        // routes whose handler doesn't type-hint it.
        Route::model('brand', Brand::class);

        Mail::extend('gmail_api', fn () => new GmailApiTransport);

        // The core's scheduled tasks (plugins add theirs the same way), and the record of every run.
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->registerSchedule($schedule));
        Event::subscribe(RecordsTaskRuns::class);

        $this->registerPermissions($this->app->make(Permissions::class));
        $this->registerSettings($this->app->make(SettingsRegistry::class));
        $this->registerNavigation($this->app->make(Navigation::class));
        $this->registerSettingsAreas($this->app->make(SettingsAreas::class));

        // A store connection can't be removed while a brand reads its catalogue from it.
        $this->app->make(Connections::class)->usedBy(fn (Connection $c) => $this->app->make(Catalogue::class)->brandsUsing($c)->map(fn (Brand $b) => "{$b->name}'s store")->all());
    }

    /**
     * What runs on the server's timer. Shown, with every run, on the
     * Scheduled tasks page.
     */
    private function registerSchedule(Schedule $schedule): void
    {
        // Horizon's metrics graphs need a snapshot every few minutes.
        $schedule->command('horizon:snapshot')->everyFiveMinutes();

        // Keeps the queue watching that the scheduler itself is alive (it can't say so once it isn't).
        $schedule->command('scheduler:watch')->everyFiveMinutes();

        // Each brand's store is read once a day, at the time in its Store settings.
        $schedule->command('catalogue:sync-due')->hourly()->withoutOverlapping();
    }

    private function registerPermissions(Permissions $permissions): void
    {
        $everyone = Role::cases();
        $makers = [Role::Admin, Role::Creator];

        $permissions->define('brands.view', 'See a brand, its gallery and its settings', $everyone);
        $permissions->define('brands.create', 'Add brands', [Role::Admin]);
        $permissions->define('brands.manage', 'Change a brand\'s settings and brand kit', $makers);
        $permissions->define('requests.create', 'Ask for creative (it calls the AI providers, so it costs money)', $makers);
        $permissions->define('requests.view_all', 'See everyone\'s requests, public or private', [Role::Admin]);
        $permissions->define('prompts.edit', 'Change a brand\'s prompts', [Role::Admin]);
        $permissions->define('prompts.personal', 'Keep personal additions to the prompts', $makers);
        $permissions->define('budgets.manage', 'Set the company and per-person budgets', [Role::Admin]);
        $permissions->define('connections.manage', 'Connect stores and AI providers', [Role::Admin]);
        $permissions->define('users.manage', 'Invite users and change roles', [Role::Admin]);
        $permissions->define('settings.manage', 'Change platform settings', [Role::Admin]);
        $permissions->define('activity.view', 'Read the activity log', [Role::Admin]);
        $permissions->define('scheduler.manage', 'See the scheduled tasks and run one now', [Role::Admin]);
    }

    private function registerSettings(SettingsRegistry $settings): void
    {
        $zones = DateTimeZone::listIdentifiers();

        $settings->define(new SettingDefinition(
            key: 'platform.timezone',
            label: 'Time zone',
            description: 'Used to show dates and times, and for schedules such as the weekly summary.',
            control: SettingControl::Select,
            default: 'America/Toronto',
            rules: ['value' => ['required', 'string', 'timezone:all']],
            section: 'General',
            options: array_combine($zones, array_map(fn ($z) => str_replace('_', ' ', $z), $zones)),
        ));

        $settings->define(new SettingDefinition(
            key: 'scheduler.stalled_minutes',
            label: 'Count the scheduled tasks as stopped after',
            description: 'How long without a task starting before the scheduler counts as stopped: a warning shows in the app and platform admins are emailed. Tasks start every minute while it runs.',
            control: SettingControl::Number,
            default: 10,
            rules: ['value' => ['required', 'integer', 'min:2', 'max:1440']],
            unit: 'minutes',
            min: 2,
            max: 1440,
            section: 'Scheduled tasks',
        ));

        $settings->define(new SettingDefinition(
            key: 'scheduler.keep_days',
            advanced: true,
            label: 'Keep the record of runs for',
            description: 'How long each scheduled task\'s runs stay on the Scheduled tasks page. Tasks start every minute, so this is a few thousand rows a day.',
            control: SettingControl::Number,
            default: 14,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:365']],
            unit: 'days',
            min: 1,
            max: 365,
            section: 'Scheduled tasks',
        ));

        $settings->define(new SettingDefinition(
            key: 'llm.anthropic_api_key',
            label: 'Anthropic API key',
            description: 'Used for every Claude model: planning, copy and review. Stored encrypted and never shown again.',
            control: SettingControl::Secret,
            default: null,
            rules: ['value' => ['nullable', 'string', 'max:500']],
            section: 'AI models',
        ));

        $settings->define(new SettingDefinition(
            key: 'llm.default_model',
            label: 'Model for planning and copy',
            description: 'Reads the brief, asks the clarifying questions, plans the run and writes the prompts and copy.',
            control: SettingControl::Select,
            default: 'claude-opus-5-5',
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'string', 'exists:llm_models,model']],
            section: 'AI models',
            options: fn () => LlmModel::options(),
        ));

        $settings->define(new SettingDefinition(
            key: 'llm.review_model',
            label: 'Model for review',
            description: 'Scores every output before anyone sees it. It looks at images, so a capable model matters more than a fast one.',
            control: SettingControl::Select,
            default: 'claude-opus-5-5',
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'string', 'exists:llm_models,model']],
            section: 'AI models',
            options: fn () => LlmModel::options(),
        ));

        $settings->define(new SettingDefinition(
            key: 'llm.effort',
            label: 'Effort',
            description: 'How much the model thinks before answering. Higher is more careful and costs more; lower is faster and cheaper.',
            control: SettingControl::Select,
            default: 'high',
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'in:'.implode(',', array_keys(Effort::OPTIONS))]],
            section: 'AI models',
            options: Effort::OPTIONS,
        ));

        $settings->define(new SettingDefinition(
            key: Catalogue::STORE_SETTING,
            label: 'Store',
            description: 'The brand\'s store connection (Connections → Shopify). Its products, sizes, flavours and photos are read from here.',
            control: SettingControl::Select,
            default: null,
            scopes: [SettingScope::Brand],
            section: 'Store',
            options: fn (int $brandId) => Connection::pickerOptions($this->app->make(PluginRegistry::class)->providing(StoreProvider::class)->keys()->all(), $brandId, Catalogue::STORE_SETTING),
            rules: ['value' => ['nullable', 'exists:connections,id']],
        ));

        $settings->define(new SettingDefinition(
            key: 'catalogue.sync_time',
            label: 'Read the store each day at',
            description: 'In the platform\'s time zone, such as 03:00. It can also be read any time from the Products page.',
            control: SettingControl::Text,
            default: '03:00',
            scopes: [SettingScope::Platform, SettingScope::Brand],
            section: 'Store',
            rules: ['value' => ['required', 'date_format:H:i']],
        ));

        $settings->define(new SettingDefinition(
            key: 'auth.allowed_domains',
            label: 'Company email domains',
            description: 'People with an email on these domains can sign in with Google. They start with no access until someone gives them a role.',
            control: SettingControl::List,
            default: ['biosteel.com', 'canadianprotein.com', 'coachwoodgroup.com', 'nuverahealth.com'],
            rules: [
                'value' => ['required', 'array', 'min:1'],
                'value.*' => ['string', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/'],
            ],
            section: 'Sign-in and invitations',
            normalize: fn ($domains) => collect((array) $domains)
                ->map(fn ($d) => strtolower(trim(ltrim(trim((string) $d), '@'))))
                ->filter()->unique()->sort()->values()->all(),
        ));

        $settings->define(new SettingDefinition(
            key: 'auth.remember_days',
            label: 'Stay signed in for',
            description: 'How long someone stays signed in on a device, even after closing the browser. Changing your password signs you out everywhere else.',
            control: SettingControl::Number,
            default: 30,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:365']],
            unit: 'days',
            section: 'Sign-in and invitations',
        ));

        $settings->define(new SettingDefinition(
            key: 'auth.sign_in_attempts_per_ip',
            label: 'Wrong passwords per minute from one address',
            description: 'Across every email, so someone can\'t try a common password on many accounts. Each email also locks after 5 wrong tries.',
            control: SettingControl::Number,
            default: 30,
            rules: ['value' => ['required', 'integer', 'min:5', 'max:1000']],
            section: 'Sign-in and invitations',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'auth.reset_requests_per_ip',
            label: 'Password reset requests per minute from one address',
            description: 'How many "Forgot your password?" emails one internet address can ask for in a minute.',
            control: SettingControl::Number,
            default: 5,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:100']],
            section: 'Sign-in and invitations',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'invitations.expiry_days',
            label: 'Invitation links expire after',
            description: 'How long an emailed invitation stays valid.',
            control: SettingControl::Number,
            default: 7,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:90']],
            unit: 'days',
            min: 1,
            max: 90,
            section: 'Sign-in and invitations',
        ));
    }

    /**
     * Where each feature's brand settings live (plugins add theirs), so Brand
     * settings keeps only the brand-wide ones.
     */
    private function registerSettingsAreas(SettingsAreas $areas): void
    {
        $areas->add(new SettingsArea('ai', 'AI models', ['AI models'], order: 10,
            description: 'Which Claude models plan, write and review for this brand, and how hard they think.'));
        $areas->add(new SettingsArea('store', 'Store', ['Store'], pageRoute: 'products', order: 20,
            description: 'Where the brand\'s products come from, and when they\'re read.'));
    }

    private function registerNavigation(Navigation $nav): void
    {
        $nav->add(new NavItem('products', 'Products', 'products', 'heroicon-o-cube', NavSection::Brand, 30, 'brands.view'));

        // Every entry of the brand's Settings keeps it the current page.
        $nav->add(new NavItem('brand-settings', 'Settings', 'brand-settings', 'heroicon-o-adjustments-horizontal', NavSection::Brand, 200, 'brands.view',
            activeOn: fn () => ['brand-settings', 'area-settings', ...array_diff(app(SettingsAreas::class)->settingsRoutes(), $nav->section(NavSection::Brand)->pluck('route')->all())]));

        $nav->add(new NavItem('connections', 'Connections', 'connections', 'heroicon-o-puzzle-piece', NavSection::Platform, 10, 'connections.manage'));
        $nav->add(new NavItem('users', 'Users', 'users', 'heroicon-o-users', NavSection::Platform, 20, 'users.manage'));
        $nav->add(new NavItem('settings', 'Settings', 'settings', 'heroicon-o-cog-6-tooth', NavSection::Platform, 30, 'settings.manage'));
        $nav->add(new NavItem('activity', 'Activity log', 'activity', 'heroicon-o-clipboard-document-list', NavSection::Platform, 40, 'activity.view'));
        $nav->add(new NavItem('scheduled-tasks', 'Scheduled tasks', 'scheduled-tasks', 'heroicon-o-clock', NavSection::Platform, 50, 'scheduler.manage'));
    }
}
