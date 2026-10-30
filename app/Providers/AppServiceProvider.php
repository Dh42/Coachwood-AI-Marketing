<?php

namespace App\Providers;

use App\Auth\Permissions;
use App\Auth\Role;
use App\Brands\CurrentBrand;
use App\Catalogue\Catalogue;
use App\Catalogue\Cutouts;
use App\Composer\ChromeRenderer;
use App\Composer\Renderer;
use App\Images\ImageSize;
use App\Llm\Effort;
use App\Llm\LlmManager;
use App\Mail\Transport\GmailApiTransport;
use App\Models\Brand;
use App\Models\Connection;
use App\Models\CreativeRequest;
use App\Models\ImageModel;
use App\Models\LlmModel;
use App\Navigation\Navigation;
use App\Navigation\NavItem;
use App\Navigation\NavSection;
use App\Notifications\RequestNotice;
use App\Plugins\Connections;
use App\Plugins\Contracts\BackgroundRemover;
use App\Plugins\Contracts\StoreProvider;
use App\Plugins\CorePluginRegistry;
use App\Plugins\PluginRegistry;
use App\Policies\RequestPolicy;
use App\Prompts\PromptTrials;
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
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rule;

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
        $this->app->singleton(LlmManager::class);
        $this->app->bind(Renderer::class, ChromeRenderer::class);

        // Scoped so each request or queued job starts with fresh values.
        $this->app->scoped(Settings::class);
    }

    public function boot(): void
    {
        // {brand} in any URL is a brand, looked up by its ID (slug), even on
        // routes whose handler doesn't type-hint it.
        Route::model('brand', Brand::class);
        Route::model('request', CreativeRequest::class);

        Mail::extend('gmail_api', fn () => new GmailApiTransport);
        Gate::policy(CreativeRequest::class, RequestPolicy::class);

        // The core's scheduled tasks (plugins add theirs the same way), and the record of every run.
        $this->callAfterResolving(Schedule::class, fn (Schedule $schedule) => $this->registerSchedule($schedule));
        Event::subscribe(RecordsTaskRuns::class);

        $this->registerPermissions($this->app->make(Permissions::class));
        $this->registerSettings($this->app->make(SettingsRegistry::class));
        $this->registerNavigation($this->app->make(Navigation::class));
        $this->registerSettingsAreas($this->app->make(SettingsAreas::class));

        // A store connection can't be removed while a brand reads its catalogue from it.
        $this->app->make(Connections::class)->usedBy(fn (Connection $c) => $this->app->make(Catalogue::class)->brandsUsing($c)->map(fn (Brand $b) => "{$b->name}'s store")->all());
        // Nor a background-removal connection a brand's Store settings name for its cut-outs.
        $this->app->make(Connections::class)->usedBy(fn (Connection $c) => $this->app->make(Cutouts::class)->brandsUsing($c)->map(fn (Brand $b) => "{$b->name}'s cut-outs")->all());
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

        // Rejected outputs lose their files once they've been kept as long as each brand's retention setting says.
        $schedule->command('outputs:prune-rejects')->dailyAt('04:00')->timezone((string) $this->app->make(Settings::class)->get('platform.timezone'))->withoutOverlapping();
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
        $permissions->define('models.manage', 'Change the model list: which models can be used and what they cost', [Role::Admin]);
        $permissions->define('platforms.manage', 'Change the platform library: placements, sizes, safe zones, copy limits', [Role::Admin]);
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
            description: 'Used to show dates and times, and for schedules such as the daily store read.',
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
            key: 'images.default_model',
            label: 'Model for images',
            description: 'The image model a request uses unless the planner or the person picks another. The list is on the Models page.',
            control: SettingControl::Select,
            default: null,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['nullable', 'string', 'max:150']],
            section: 'AI models',
            options: fn () => ImageModel::options(),
        ));

        $settings->define(new SettingDefinition(
            key: 'images.default_size',
            label: 'Image size',
            description: 'How big generated images are, by the longer side. 1K is enough for every feed and story placement; 2K for print-like detail or cropping room. Bigger costs more.',
            control: SettingControl::Select,
            default: ImageSize::DEFAULT,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'in:'.implode(',', array_keys(ImageSize::OPTIONS))]],
            section: 'AI models',
            options: ImageSize::OPTIONS,
        ));

        $settings->define(new SettingDefinition(
            key: 'generation.per_ratio',
            label: 'Images per shape',
            description: 'How many images are generated for each shape in a run, unless the request says otherwise. More gives the review more to choose from and costs more.',
            control: SettingControl::Number,
            default: 8,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:1', 'max:20']],
            min: 1,
            max: 20,
            section: 'Generation',
        ));

        $settings->define(new SettingDefinition(
            key: 'generation.retries',
            label: 'Tries per image when the provider is busy',
            description: 'How many more times one image is asked for after a rate limit or an outage, before that image counts as failed. Refusals aren\'t tried again.',
            control: SettingControl::Number,
            default: 2,
            rules: ['value' => ['required', 'integer', 'min:0', 'max:5']],
            min: 0,
            max: 5,
            section: 'Generation',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'iterate.max_changes',
            label: 'Changes per image',
            description: 'How many times "Make a change" may be asked on one image, counting every version made from it. After that the app suggests a fresh request with the changes folded into the brief, which gives better results than changing again.',
            control: SettingControl::Number,
            default: 3,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:1', 'max:20']],
            min: 1,
            max: 20,
            section: 'Changes',
        ));

        $settings->define(new SettingDefinition(
            key: 'iterate.max_depth',
            label: 'Steps from the original',
            description: 'A change is made from the original image with every change asked so far folded into one instruction, so repeated changes don\'t degrade. 1 means always from the original; 2 lets a change build on one earlier change, and so on.',
            control: SettingControl::Number,
            default: 1,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:1', 'max:5']],
            min: 1,
            max: 5,
            section: 'Changes',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'retention.rejects_days',
            label: 'Keep rejected outputs for',
            description: 'Rejected images and ads stay on the request page this long in case the reviewer was wrong, then their files are deleted every night at 4 AM. Their score and reason stay. Shortlisted outputs are kept.',
            control: SettingControl::Number,
            default: 1,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:1', 'max:90']],
            unit: 'days',
            min: 1,
            max: 90,
            section: 'Review and quality',
        ));

        $settings->define(new SettingDefinition(
            key: 'notify.email',
            label: 'Email about',
            description: 'Every one of these shows as a notice under the bell at the top of the page; the ticked ones are emailed too. This is the starting choice for everyone; each person changes their own under Your account.',
            control: SettingControl::Checklist,
            default: [RequestNotice::NEEDS_YOU, RequestNotice::DONE],
            scopes: [SettingScope::Platform, SettingScope::User],
            rules: ['value' => ['array'], 'value.*' => ['string', Rule::in(array_keys(RequestNotice::EVENTS))]],
            section: 'Notifications',
            normalize: fn ($value) => array_values(array_unique(array_map('strval', (array) $value))),
            options: RequestNotice::EVENTS,
        ));

        $settings->define(new SettingDefinition(
            key: 'notify.email_delay',
            label: 'Wait before emailing',
            description: 'A notice seen in the app within this time isn\'t emailed as well, so someone watching their request isn\'t told twice.',
            control: SettingControl::Number,
            default: 2,
            rules: ['value' => ['required', 'integer', 'min:0', 'max:120']],
            unit: 'minutes',
            min: 0,
            max: 120,
            section: 'Notifications',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'review.min_score',
            label: 'Minimum score to deliver',
            description: 'Out of 100, from the reviewer\'s weighted scores. Nothing under it reaches the shortlist; it goes to Rejected with the reason.',
            control: SettingControl::Number,
            default: 70,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:0', 'max:100']],
            min: 0,
            max: 100,
            section: 'Review and quality',
        ));

        $settings->define(new SettingDefinition(
            key: 'review.deliver',
            label: 'Deliver the best',
            description: 'How many of what passes to deliver per shape (clean images) and per placement (finished ads), unless the request says otherwise.',
            control: SettingControl::Number,
            default: 3,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:1', 'max:20']],
            min: 1,
            max: 20,
            section: 'Review and quality',
        ));

        $settings->define(new SettingDefinition(
            key: 'review.image_px',
            label: 'Picture size sent to the reviewer',
            description: 'The longer side, in pixels. Bigger shows the reviewer more detail (small text, labels) and costs more per review.',
            control: SettingControl::Number,
            default: 1024,
            rules: ['value' => ['required', 'integer', 'min:512', 'max:2048']],
            unit: 'px',
            min: 512,
            max: 2048,
            section: 'Review and quality',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'copy.versions',
            label: 'Copy versions per placement',
            description: 'How many different takes the copywriter writes for each placement and language, so the team can test.',
            control: SettingControl::Number,
            default: 3,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:1', 'max:8']],
            min: 1,
            max: 8,
            section: 'Generation',
        ));

        $settings->define(new SettingDefinition(
            key: 'copy.rewrites',
            label: 'Rewrites when copy is over a limit',
            description: 'The app counts every piece; when one is over its placement\'s limit the writer is asked to shorten it, this many times, before the version is shown as over.',
            control: SettingControl::Number,
            default: 2,
            rules: ['value' => ['required', 'integer', 'min:0', 'max:5']],
            min: 0,
            max: 5,
            section: 'Generation',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'composer.chrome_path',
            label: 'Chrome, for finished ads',
            description: 'The command or path of the Chrome or Chromium that renders finished ads on the server.',
            control: SettingControl::Text,
            default: 'google-chrome',
            rules: ['value' => ['required', 'string', 'max:200']],
            section: 'Generation',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'composer.timeout_seconds',
            label: 'Wait for a finished ad for up to',
            description: 'How long one render in Chrome may take.',
            control: SettingControl::Number,
            default: 60,
            rules: ['value' => ['required', 'integer', 'min:10', 'max:300']],
            unit: 'seconds',
            min: 10,
            max: 300,
            section: 'Generation',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'requests.max_questions',
            label: 'Questions the planner may ask at once',
            description: 'In one round, before it shows the plan.',
            control: SettingControl::Number,
            default: 3,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:6']],
            min: 1,
            max: 6,
            section: 'Generation',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'requests.max_rounds',
            label: 'Rounds of questions',
            description: 'After this many rounds the planner decides with sensible assumptions instead of asking again.',
            control: SettingControl::Number,
            default: 2,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:5']],
            min: 1,
            max: 5,
            section: 'Generation',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'requests.max_reference_mb',
            label: 'Largest reference image',
            description: 'The biggest image file a request may carry.',
            control: SettingControl::Number,
            default: 25,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:200']],
            unit: 'MB',
            min: 1,
            max: 200,
            section: 'Generation',
            advanced: true,
        ));

        $settings->define(new SettingDefinition(
            key: 'budgets.company_monthly',
            label: 'Company budget each month',
            description: 'All AI spend across every brand and person: images, video, planning, copy and review. No new run starts once it\'s used up; a run already going always finishes.',
            control: SettingControl::Number,
            default: 1000,
            rules: ['value' => ['required', 'numeric', 'min:0', 'max:1000000']],
            unit: '$',
            min: 0,
            section: 'Budgets',
        ));

        $settings->define(new SettingDefinition(
            key: 'budgets.alert_percent',
            label: 'Warn at',
            description: 'How much of the company budget may go before admins are told.',
            control: SettingControl::Number,
            default: 80,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:100']],
            unit: '%',
            min: 1,
            max: 100,
            section: 'Budgets',
        ));

        $settings->define(new SettingDefinition(
            key: 'budgets.user_monthly',
            label: 'Each person\'s budget each month',
            description: 'Unless an admin sets a person their own. When it\'s used up that person can\'t start a run until next month or until it\'s raised.',
            control: SettingControl::Number,
            default: 200,
            rules: ['value' => ['required', 'numeric', 'min:0', 'max:1000000']],
            unit: '$',
            min: 0,
            section: 'Budgets',
        ));

        $settings->define(new SettingDefinition(
            key: 'budgets.text_allowance',
            label: 'Allow per run for writing and review',
            description: 'Added to each run\'s estimate for the text model\'s copy and review calls, which can\'t be priced exactly before the run. The actual cost replaces it afterwards.',
            control: SettingControl::Number,
            default: 1,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'numeric', 'min:0', 'max:100']],
            unit: '$',
            min: 0,
            max: 100,
            section: 'Budgets',
        ));

        $settings->define(new SettingDefinition(
            key: PromptTrials::SETTING,
            label: 'Test budget per person per day',
            description: 'What each person may spend a day on "Try it" on the Prompts page. A planner, copy, review or changes try is one text-model call; an images try makes one small picture. Tries count toward the monthly budgets too. 0 switches "Try it" off.',
            control: SettingControl::Number,
            default: 2,
            scopes: [SettingScope::Platform],
            rules: ['value' => ['required', 'numeric', 'min:0', 'max:100']],
            unit: '$',
            min: 0,
            max: 100,
            section: 'Budgets',
        ));

        $settings->define(new SettingDefinition(
            key: 'images.timeout_seconds',
            label: 'Wait for an image for up to',
            description: 'How long one image call may take before it counts as failed and is tried again. Complex prompts on the slower models can take two minutes.',
            control: SettingControl::Number,
            default: 180,
            rules: ['value' => ['required', 'integer', 'min:30', 'max:600']],
            unit: 'seconds',
            min: 30,
            max: 600,
            section: 'AI models',
            advanced: true,
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
            key: Cutouts::SETTING,
            label: 'Cut-outs by',
            description: 'Which background-removal connection (Connections → Photoroom) cuts the product out of the store\'s photos, for finished ads when the brand kit has no transparent pack shot. Left empty, any working one the brand may use.',
            control: SettingControl::Select,
            default: null,
            scopes: [SettingScope::Brand],
            section: 'Store',
            options: fn (int $brandId) => Connection::pickerOptions($this->app->make(PluginRegistry::class)->providing(BackgroundRemover::class)->keys()->all(), $brandId, Cutouts::SETTING),
            rules: ['value' => ['nullable', 'exists:connections,id']],
        ));

        $settings->define(new SettingDefinition(
            key: 'brand_kit.min_references',
            label: 'Style references needed',
            description: 'How many style reference images a brand kit needs before the brand can generate.',
            control: SettingControl::Number,
            default: 10,
            scopes: [SettingScope::Platform, SettingScope::Brand],
            rules: ['value' => ['required', 'integer', 'min:0', 'max:100']],
            unit: 'images',
            min: 0,
            max: 100,
            section: 'Brand kit',
        ));

        $settings->define(new SettingDefinition(
            key: 'brand_kit.max_upload_mb',
            label: 'Largest file the brand kit takes',
            description: 'Logos, reference images, fonts, tracks and pack shots bigger than this are refused.',
            control: SettingControl::Number,
            default: 25,
            rules: ['value' => ['required', 'integer', 'min:1', 'max:60']],
            unit: 'MB',
            min: 1,
            max: 60,
            section: 'Brand kit',
            advanced: true,
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
            description: 'Which models plan, write, review and make images for this brand, and how hard they think. The model list itself is on the Models page.'));
        $areas->add(new SettingsArea('generation', 'Generation', ['Generation', 'Changes'], order: 12,
            description: 'How many images each run makes, how the planner asks its questions, and how changes on an image are made.'));
        $areas->add(new SettingsArea('review', 'Review and quality', ['Review and quality'], order: 13,
            description: 'What the reviewer must score before the team sees an output, and how many to deliver.'));
        $areas->add(new SettingsArea('budgets', 'Budgets and spend', ['Budgets'], order: 14,
            description: 'The company\'s and each person\'s monthly limit, and the allowance for writing and review.'));
        $areas->add(new SettingsArea('brand-kit', 'Brand kit', ['Brand kit'], pageRoute: 'brand-kit', order: 15,
            description: 'What the brand kit needs before the brand can generate.'));
        $areas->add(new SettingsArea('prompts', 'Prompts', [], pageRoute: 'prompts', order: 16,
            description: 'What the AI is told at each step, with every version kept.'));
        $areas->add(new SettingsArea('store', 'Store', ['Store'], pageRoute: 'products', order: 20,
            description: 'Where the brand\'s products come from, and when they\'re read.'));
    }

    private function registerNavigation(Navigation $nav): void
    {
        $nav->add(new NavItem('requests', 'Requests', 'requests', 'heroicon-o-sparkles', NavSection::Brand, 10, 'brands.view', activeOn: fn () => ['requests', 'requests.create', 'requests.show']));
        $nav->add(new NavItem('products', 'Products', 'products', 'heroicon-o-cube', NavSection::Brand, 30, 'brands.view'));
        $nav->add(new NavItem('brand-kit', 'Brand kit', 'brand-kit', 'heroicon-o-swatch', NavSection::Brand, 40, 'brands.view'));
        $nav->add(new NavItem('prompts', 'Prompts', 'prompts', 'heroicon-o-document-text', NavSection::Brand, 45, 'brands.view'));
        $nav->add(new NavItem('layouts', 'Layouts', 'layouts', 'heroicon-o-squares-2x2', NavSection::Brand, 50, 'brands.view'));

        // Every entry of the brand's Settings keeps it the current page.
        $nav->add(new NavItem('brand-settings', 'Settings', 'brand-settings', 'heroicon-o-adjustments-horizontal', NavSection::Brand, 200, 'brands.view',
            activeOn: fn () => ['brand-settings', 'area-settings', ...array_diff(app(SettingsAreas::class)->settingsRoutes(), $nav->section(NavSection::Brand)->pluck('route')->all())]));

        $nav->add(new NavItem('connections', 'Connections', 'connections', 'heroicon-o-puzzle-piece', NavSection::Platform, 10, 'connections.manage'));
        $nav->add(new NavItem('platforms', 'Platforms', 'platforms', 'heroicon-o-rectangle-stack', NavSection::Platform, 15, 'platforms.manage'));
        $nav->add(new NavItem('models', 'Models', 'models', 'heroicon-o-cpu-chip', NavSection::Platform, 17, 'models.manage'));
        $nav->add(new NavItem('users', 'Users', 'users', 'heroicon-o-users', NavSection::Platform, 20, 'users.manage'));
        $nav->add(new NavItem('spend', 'Spend', 'spend', 'heroicon-o-banknotes', NavSection::Platform, 25, 'budgets.manage'));
        $nav->add(new NavItem('settings', 'Settings', 'settings', 'heroicon-o-cog-6-tooth', NavSection::Platform, 30, 'settings.manage'));
        $nav->add(new NavItem('activity', 'Activity log', 'activity', 'heroicon-o-clipboard-document-list', NavSection::Platform, 40, 'activity.view'));
        $nav->add(new NavItem('scheduled-tasks', 'Scheduled tasks', 'scheduled-tasks', 'heroicon-o-clock', NavSection::Platform, 50, 'scheduler.manage'));
    }
}
