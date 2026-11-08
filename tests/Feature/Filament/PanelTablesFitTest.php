<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use Filament\Actions\ActionGroup;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Livewire;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\ListSurveyTemplates;
use Modules\Surveys\Filament\Widgets\PendingSurveyReviewsWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The panel tables measured wider than their container at every width, so the row actions
 * fell outside the view: the actions sit in one menu and the secondary columns only show
 * from the width where they fit.
 */
final class PanelTablesFitTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class, SurveyTemplateResource::class]);
        $this->actingAsPanelAdmin();
    }

    /**
     * @return array<string, array{class-string<HasTable>}>
     */
    public static function tablesWithSeveralActions(): array
    {
        return [
            'surveys' => [ListSurveys::class],
            'templates' => [ListSurveyTemplates::class],
        ];
    }

    /**
     * @param  class-string<HasTable>  $page
     */
    #[DataProvider('tablesWithSeveralActions')]
    public function test_row_actions_sit_in_a_menu(string $page): void
    {
        $actions = $this->tableOf($page)->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertInstanceOf(ActionGroup::class, $actions[0]);
    }

    public function test_the_review_widget_keeps_its_single_action(): void
    {
        $actions = $this->tableOf(PendingSurveyReviewsWidget::class)->getRecordActions();

        $this->assertCount(1, $actions);
        $this->assertNotInstanceOf(ActionGroup::class, $actions[0]);
    }

    /**
     * Column => breakpoint it shows from; null for the ones that always show.
     *
     * @return array<string, array{class-string<HasTable>, array<string, string|null>}>
     */
    public static function columnBreakpoints(): array
    {
        return [
            'surveys' => [ListSurveys::class, [
                'title' => null,
                'status' => null,
                'responses_count' => 'sm',
                'kind' => 'md',
                'subject' => 'xl',
                'closes_at' => 'xl',
                'anonymous' => '2xl',
            ]],
            'templates' => [ListSurveyTemplates::class, [
                'name' => null,
                'kind' => 'sm',
                'questions_count' => 'md',
                'uses' => 'xl',
                'updated_at' => 'xl',
            ]],
            'pending reviews' => [PendingSurveyReviewsWidget::class, [
                'title' => null,
                'subject' => 'md',
                'submitted_by' => 'md',
                'submitted_for_review_at' => 'xl',
            ]],
        ];
    }

    /**
     * @param  class-string<HasTable>  $page
     * @param  array<string, string|null>  $breakpoints
     */
    #[DataProvider('columnBreakpoints')]
    public function test_secondary_columns_show_only_from_the_width_where_they_fit(string $page, array $breakpoints): void
    {
        $table = $this->tableOf($page);

        foreach ($breakpoints as $name => $breakpoint) {
            $column = $table->getColumn($name);

            $this->assertNotNull($column, "Column {$name} is missing");
            $this->assertSame($breakpoint, $column->getVisibleFrom(), "Column {$name}");
        }
    }

    /**
     * @param  class-string<HasTable>  $page
     */
    private function tableOf(string $page): Table
    {
        // A dashboard widget is lazy and only mounts for those who may review: its table is built on its own
        if (is_subclass_of($page, TableWidget::class)) {
            $widget = new $page;

            return $widget->table(Table::make($widget));
        }

        $instance = Livewire::test($page)->instance();
        $this->assertInstanceOf(HasTable::class, $instance);

        return $instance->getTable();
    }
}
