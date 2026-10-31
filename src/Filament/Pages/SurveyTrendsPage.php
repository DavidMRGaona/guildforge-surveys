<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Pages;

use App\Filament\Concerns\ChecksPermissions;
use App\Filament\Concerns\SafeModuleNavigation;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Livewire\Attributes\Computed;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\Trends\TemplateTrendsDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendComparison;
use Modules\Surveys\Application\DTOs\Trends\TrendFilterOptionsDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\DTOs\Trends\TrendPeriod;
use Modules\Surveys\Application\Services\TrendsService;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

/**
 * How each keyed question of a template evolves across its surveys, with filters and
 * comparisons. Open to surveys.view_results: whoever reads results reads their trends.
 *
 * @property Schema $form
 */
final class SurveyTrendsPage extends Page implements HasForms
{
    use ChecksPermissions;
    use InteractsWithForms;
    use SafeModuleNavigation;

    public const string PERMISSION = 'surveys:surveys.view_results';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'survey-trends';

    protected string $view = 'surveys::filament.survey-trends';

    /**
     * @var array<string, mixed>
     */
    public ?array $filters = [];

    public static function getNavigationGroup(): string
    {
        return __('surveys::filament.navigation_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('surveys::filament.trends.title');
    }

    public function getTitle(): string
    {
        return __('surveys::filament.trends.title');
    }

    public static function canAccess(): bool
    {
        return self::userCan(self::PERMISSION);
    }

    public function mount(): void
    {
        $requested = request()->query('template');
        $ids = array_column($this->templates(), 'id');

        $this->form->fill([
            'template' => is_string($requested) && in_array($requested, $ids, true) ? $requested : ($ids[0] ?? null),
            'period' => TrendPeriod::Quarter->value,
            'compare' => TrendComparison::None->value,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 4])->schema([
                    Select::make('template')
                        ->label(__('surveys::filament.trends.fields.template'))
                        ->options(fn (): array => array_column($this->templates(), 'name', 'id'))
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('type', null);
                            $set('organizer', null);
                            $set('system', null);
                        }),
                    Select::make('type')
                        ->label(__('surveys::filament.trends.fields.type'))
                        ->options(fn (): array => array_column($this->filterOptions()->subjectTypes ?? [], 'label', 'value'))
                        ->placeholder(__('surveys::filament.trends.all'))
                        ->native(false)
                        ->live(),
                    Select::make('organizer')
                        ->label(__('surveys::filament.trends.fields.organizer'))
                        ->options(fn (): array => array_column($this->filterOptions()->organizers ?? [], 'label', 'value'))
                        ->placeholder(__('surveys::filament.trends.all'))
                        ->searchable()
                        ->native(false)
                        ->live(),
                    Select::make('system')
                        ->label(__('surveys::filament.trends.fields.system'))
                        ->options(fn (): array => array_combine($this->filterOptions()->gameSystems ?? [], $this->filterOptions()->gameSystems ?? []))
                        ->placeholder(__('surveys::filament.trends.all'))
                        ->searchable()
                        ->native(false)
                        ->live(),
                    DatePicker::make('from')
                        ->label(__('surveys::filament.trends.fields.from'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->live(),
                    DatePicker::make('to')
                        ->label(__('surveys::filament.trends.fields.to'))
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->live(),
                    Select::make('period')
                        ->label(__('surveys::filament.trends.fields.period'))
                        ->options($this->enumOptions(TrendPeriod::cases(), 'periods'))
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->live(),
                    Select::make('compare')
                        ->label(__('surveys::filament.trends.fields.compare'))
                        ->options($this->enumOptions(TrendComparison::cases(), 'comparisons'))
                        ->native(false)
                        ->selectablePlaceholder(false)
                        ->live(),
                ]),
            ])
            ->statePath('filters');
    }

    /**
     * The trends of the chosen template, or null when there is none to choose.
     */
    #[Computed]
    public function trends(): ?TemplateTrendsDTO
    {
        $templateId = $this->templateId();

        return $templateId instanceof SurveyTemplateId
            ? app(TrendsService::class)->forPanel($templateId, $this->trendFilters())
            : null;
    }

    public function templateId(): ?SurveyTemplateId
    {
        $chosen = $this->filters['template'] ?? null;

        // Livewire state can be sent by hand: only a template that has surveys counts
        return is_string($chosen) && in_array($chosen, array_column($this->templates(), 'id'), true)
            ? SurveyTemplateId::fromString($chosen)
            : null;
    }

    /**
     * The cut as the chart widgets read it.
     *
     * @return array<string, mixed>
     */
    public function filterInput(): array
    {
        return $this->trendFilters()->toInput();
    }

    public function minimum(): int
    {
        return app(SurveySettingsInterface::class)->minResponses();
    }

    private function trendFilters(): TrendFilters
    {
        return TrendFilters::fromInput($this->filters ?? []);
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    #[Computed]
    private function templates(): array
    {
        return app(TrendsService::class)->templatesFor();
    }

    #[Computed]
    private function filterOptions(): ?TrendFilterOptionsDTO
    {
        $templateId = $this->templateId();

        return $templateId instanceof SurveyTemplateId ? app(TrendsService::class)->filterOptions($templateId) : null;
    }

    /**
     * @param  list<TrendPeriod>|list<TrendComparison>  $cases
     * @return array<string, string>
     */
    private function enumOptions(array $cases, string $group): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[$case->value] = __("surveys::filament.trends.{$group}.{$case->value}");
        }

        return $options;
    }
}
