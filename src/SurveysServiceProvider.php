<?php

declare(strict_types=1);

namespace Modules\Surveys;

use App\Application\Calendar\Contracts\CalendarEntrySourceInterface;
use App\Application\Modules\DTOs\ModuleRouteDTO;
use App\Application\Modules\DTOs\NavigationItemDTO;
use App\Application\Modules\DTOs\PagePrefixDTO;
use App\Application\Modules\DTOs\PermissionDTO;
use App\Application\Modules\DTOs\SlotRegistrationDTO;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Domain\Surveys\Events\SurveySubjectEnded;
use App\Modules\ModuleServiceProvider;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Widgets\Widget;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Modules\Surveys\Application\Contracts\AudienceResolverInterface;
use Modules\Surveys\Application\Contracts\OrganizerNotifierInterface;
use Modules\Surveys\Application\Contracts\PendingSurveysQueryInterface;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Application\Contracts\ReviewNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveyRecipientStoreInterface;
use Modules\Surveys\Application\Contracts\SurveyResultsQueryInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Console\Commands\CloseDueSurveysCommand;
use Modules\Surveys\Console\Commands\SendSurveyRemindersCommand;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Filament\Pages\SurveySettingsPage;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentPendingSurveysQuery;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentResponseStore;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentSurveyRecipientStore;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentSurveyRepository;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentSurveyResultsQuery;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentSurveyTemplateRepository;
use Modules\Surveys\Infrastructure\Services\EloquentAudienceResolver;
use Modules\Surveys\Infrastructure\Services\OrganizerMailer;
use Modules\Surveys\Infrastructure\Services\ReviewMailer;
use Modules\Surveys\Infrastructure\Services\SurveyInvitationSender;
use Modules\Surveys\Infrastructure\Services\SurveySettings;
use Modules\Surveys\Listeners\OfferSurveyWhenSubjectEnds;
use Modules\Surveys\Policies\SurveyPolicy;
use Modules\Surveys\Policies\SurveyTemplatePolicy;

final class SurveysServiceProvider extends ModuleServiceProvider
{
    /**
     * Get the module name (kebab-case).
     */
    public function moduleName(): string
    {
        return 'surveys';
    }

    /**
     * Register module services.
     *
     * IMPORTANT: Register model extensions (macros) here, not in boot().
     * This ensures they're available before Filament loads Resources.
     */
    public function register(): void
    {
        parent::register();

        // Register model extensions (macros) early
        // $this->registerModelExtensions();

        // config/module.php is loaded by parent::register() into "modules.surveys"

        $this->app->bind(SurveyRepositoryInterface::class, EloquentSurveyRepository::class);
        $this->app->bind(SurveyTemplateRepositoryInterface::class, EloquentSurveyTemplateRepository::class);
        $this->app->bind(SurveyRecipientStoreInterface::class, EloquentSurveyRecipientStore::class);
        $this->app->bind(AudienceResolverInterface::class, EloquentAudienceResolver::class);
        $this->app->bind(ResponseStoreInterface::class, EloquentResponseStore::class);
        $this->app->bind(SurveyNotifierInterface::class, SurveyInvitationSender::class);
        $this->app->bind(PendingSurveysQueryInterface::class, EloquentPendingSurveysQuery::class);
        $this->app->bind(SurveyResultsQueryInterface::class, EloquentSurveyResultsQuery::class);
        $this->app->bind(SurveySettingsInterface::class, SurveySettings::class);
        $this->app->bind(OrganizerNotifierInterface::class, OrganizerMailer::class);
        $this->app->bind(ReviewNotifierInterface::class, ReviewMailer::class);
    }

    /**
     * Bootstrap module services.
     */
    public function boot(): void
    {
        parent::boot();

        // Register Livewire components (required for Filament RelationManagers)
        // $this->registerLivewireComponents();

        // Extend core Filament Resources with RelationManagers
        // $this->registerFilamentExtensions();

        $this->registerEventListeners();

        $this->registerCommands();
        $this->registerSchedule();
        $this->registerRateLimiters();
        $this->sharePendingSurveys();
    }

    /**
     * A subject offered by another module ended: send or offer its survey.
     */
    private function registerEventListeners(): void
    {
        Event::listen(SurveySubjectEnded::class, OfferSurveyWhenSubjectEnds::class);
    }

    /**
     * The profile's "Encuestas pendientes" tab: only computed on the profile page.
     */
    private function sharePendingSurveys(): void
    {
        $pending = static function (): ?array {
            $user = auth()->user();
            if (request()->route()?->getName() !== 'profile.show' || $user === null) {
                return null;
            }

            return app(PendingSurveysQueryInterface::class)->forUser((string) $user->getAuthIdentifier(), now()->toDateTimeImmutable());
        };

        Inertia::share('profilePendingSurveys', $pending);
        Inertia::share('profilePendingSurveysTotal', static function () use ($pending): ?int {
            $surveys = $pending();

            return $surveys === null ? null : count($surveys);
        });
    }

    /**
     * Answering pages are open to guests: limit them per IP address.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('surveys-responses', static fn (Request $request): array => [
            Limit::perMinute(20)->by('surveys-responses-minute:'.$request->ip()),
            Limit::perHour(200)->by('surveys-responses-hour:'.$request->ip()),
        ]);
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CloseDueSurveysCommand::class,
                SendSurveyRemindersCommand::class,
            ]);
        }
    }

    private function registerSchedule(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Closes surveys whose date has passed and forgets their guests' email addresses
            $schedule->command('surveys:close-due')
                ->everyFiveMinutes()
                ->withoutOverlapping(10);

            // Reminds who has not answered, once per survey, when its reminder time comes
            $schedule->command('surveys:send-reminders')
                ->hourly()
                ->withoutOverlapping(30);
        });
    }

    // ============================================================
    // EXTENDING CORE FUNCTIONALITY (uncomment and adapt as needed)
    // ============================================================

    /**
     * Register dynamic relationships on core models.
     * Uses resolveRelationUsing() - the correct Laravel way to add relationships dynamically.
     * Called in register() to ensure relationships are available before Filament loads.
     *
     * Example: Add an 'items' relationship to a core model:
     *
     *     private function registerModelExtensions(): void
     *     {
     *         // resolveRelationUsing is idempotent - calling multiple times is safe
     *         CoreModel::resolveRelationUsing('items', function (CoreModel $model) {
     *             return $model->hasMany(ItemModel::class, 'core_id', 'id');
     *         });
     *     }
     *
     * Note: Don't use Model::macro() for relationships - it forwards to Query Builder.
     */

    /**
     * Register Livewire components from the module.
     * Required for Filament RelationManagers to work properly.
     *
     * Example:
     *
     *     private function registerLivewireComponents(): void
     *     {
     *         if (! class_exists(\Livewire\Livewire::class)) {
     *             return;
     *         }
     *
     *         \Livewire\Livewire::component(
     *             'modules.surveys.filament.relation-managers.items-relation-manager',
     *             ItemsRelationManager::class
     *         );
     *     }
     */

    /**
     * Extend core Filament Resources with this module's RelationManagers.
     * Uses the HasExtendableRelations trait on core Resources.
     *
     * Example:
     *
     *     private function registerFilamentExtensions(): void
     *     {
     *         if (class_exists(\App\Filament\Resources\EventResource::class)) {
     *             \App\Filament\Resources\EventResource::extendRelations([
     *                 ItemsRelationManager::class,
     *             ]);
     *         }
     *     }
     */

    /**
     * Register policies provided by this module.
     *
     * Policies created by module:make-filament-resource stay inactive until listed here:
     *
     *     return [ItemModel::class => ItemPolicy::class];
     *
     * @return array<class-string, class-string> Map of model class to policy class
     */
    public function registerPolicies(): array
    {
        return [
            SurveyModel::class => SurveyPolicy::class,
            SurveyTemplateModel::class => SurveyTemplatePolicy::class,
        ];
    }

    /**
     * Register Filament navigation groups provided by this module.
     * Groups will only be added if they don't already exist in core.
     *
     * @return array<string, array{icon?: string, sort?: int}> Map of group label to options
     */
    public function registerNavigationGroups(): array
    {
        return [];
    }

    /**
     * Register permissions provided by this module.
     *
     * They are stored as "{module}:{name}", the key the generated policies check
     * ("surveys:items.view_any" for the example below):
     *
     *     return [
     *         new PermissionDTO(
     *             name: 'items.view_any',
     *             label: __('surveys::messages.permissions.items.view_any'),
     *             group: __('surveys::messages.title'),
     *             module: 'surveys',
     *             roles: ['editor'],
     *         ),
     *     ];
     *
     * @return array<PermissionDTO>
     */
    public function registerPermissions(): array
    {
        // Never granted or suggested to any role: each association decides who gets them
        return array_map(
            fn (string $action): PermissionDTO => new PermissionDTO(
                name: "surveys.{$action}",
                label: __("surveys::messages.permissions.{$action}"),
                group: __('surveys::messages.title'),
                description: __("surveys::messages.permissions.{$action}_description"),
                module: $this->moduleName(),
            ),
            ['manage', 'view_results', 'moderate'],
        );
    }

    /**
     * Register navigation items provided by this module.
     *
     * @return array<NavigationItemDTO>
     */
    public function registerNavigation(): array
    {
        return [];
    }

    /**
     * Register slot components provided by this module.
     * Slots allow modules to inject Vue components into layout positions.
     *
     * @return array<SlotRegistrationDTO>
     */
    public function registerSlots(): array
    {
        return [
            new SlotRegistrationDTO(
                slot: 'profile-sections',
                component: 'components/profile/ProfilePendingSurveysSection.vue',
                module: $this->moduleName(),
                order: 40,
                dataKeys: ['profilePendingSurveys'],
                profileTab: [
                    'tabId' => 'surveys',
                    'icon' => 'pencil-square',
                    'labelKey' => 'surveys.profile.tab_label',
                    'badgeKey' => 'profilePendingSurveysTotal',
                ],
            ),
        ];
    }

    /**
     * Register public routes for menu item configuration.
     * Allows module routes to appear in the menu item dropdown.
     *
     * @return array<ModuleRouteDTO>
     */
    public function registerRoutes(): array
    {
        return [];
    }

    /**
     * Register page prefixes provided by this module.
     * Allows module Vue pages to be resolved by Inertia: module:make-vue-page writes
     * pages to resources/js/pages/Surveys/, rendered as "Surveys/<Page>".
     * With usesCoreLayout the core renders its layout around them: pages never wrap their own.
     *
     * @return array<PagePrefixDTO>
     */
    public function registerPagePrefixes(): array
    {
        return [
            new PagePrefixDTO(prefix: 'Surveys', module: $this->moduleName(), usesCoreLayout: true),
        ];
    }

    /**
     * Register Filament widgets this module adds to the dashboard.
     * Widgets created by module:make-widget only appear once listed here;
     * admins can then toggle and order them on the dashboard settings page.
     *
     * @return array<class-string<Widget>>
     */
    public function registerFilamentWidgets(): array
    {
        return [];
    }

    /**
     * Register Filament pages provided by this module (resources are discovered
     * from src/Filament/Resources).
     *
     * @return array<class-string<Page>>
     */
    public function registerFilamentPages(): array
    {
        return [SurveySettingsPage::class];
    }

    /**
     * Register calendar sources provided by this module.
     * Allows module entries to appear in the public calendars.
     *
     * @return array<class-string<CalendarEntrySourceInterface>>
     */
    public function registerCalendarSources(): array
    {
        return [];
    }

    /**
     * Register survey subject sources provided by this module.
     * Allows module records to be surveyed when the surveys module is installed.
     *
     * @return array<class-string<SurveySubjectSourceInterface>>
     */
    public function registerSurveySubjectSources(): array
    {
        return [];
    }

    /**
     * Get the Filament form schema for module settings.
     * Override to provide configurable settings that appear in the admin panel.
     *
     * @return array<Component>
     */
    public function getSettingsSchema(): array
    {
        // The settings live on SurveySettingsPage, open to surveys.manage instead of modules.configure
        return [];
    }
}
