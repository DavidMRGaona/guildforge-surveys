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
use App\Modules\ModuleServiceProvider;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Widgets\Widget;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentSurveyRepository;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories\EloquentSurveyTemplateRepository;
use Modules\Surveys\Infrastructure\Services\SurveySettings;
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

        // Register domain event listeners
        // $this->registerEventListeners();
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
     * Register domain event listeners.
     *
     * Example:
     *
     *     private function registerEventListeners(): void
     *     {
     *         \Illuminate\Support\Facades\Event::listen(
     *             ItemCreated::class,
     *             SendItemCreatedNotification::class
     *         );
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
        return [];
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
        return [];
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
        return [
            Section::make(__('surveys::messages.settings.results'))
                ->schema([
                    TextInput::make('min_responses')
                        ->label(__('surveys::messages.settings.min_responses'))
                        ->helperText(__('surveys::messages.settings.min_responses_help'))
                        ->integer()
                        ->minValue(0)
                        ->required()
                        ->default(SurveySettings::DEFAULT_MIN_RESPONSES),
                ]),
            Section::make(__('surveys::messages.settings.defaults'))
                ->schema([
                    TextInput::make('default_close_days')
                        ->label(__('surveys::messages.settings.default_close_days'))
                        ->helperText(__('surveys::messages.settings.default_close_days_help'))
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->suffix(__('surveys::messages.settings.days'))
                        ->default(SurveySettings::DEFAULT_CLOSE_DAYS),
                    Toggle::make('anonymous_by_default')
                        ->label(__('surveys::messages.settings.anonymous_by_default'))
                        ->helperText(__('surveys::messages.settings.anonymous_by_default_help'))
                        ->default(true),
                ]),
        ];
    }
}
