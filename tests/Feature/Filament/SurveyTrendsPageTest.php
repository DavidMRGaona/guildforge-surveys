<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Actions\Testing\TestAction;
use Livewire\Component;
use Livewire\Livewire;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\ListSurveyTemplates;
use Modules\Surveys\Filament\Widgets\QuestionTrendChart;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\SurveysServiceProvider;
use Modules\Surveys\Tests\Support\BuildsTrendSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use ReflectionMethod;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyTrendsPageTest extends ModuleTestCase
{
    use BuildsTrendSurveys;
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        config(['modules.settings.surveys.min_responses' => 3]);
        $this->registerOnAdminPanel([SurveyTemplateResource::class], [SurveyTrendsPage::class]);
        $this->template = $this->saveTemplate($this->definition());
    }

    public function test_the_provider_registers_the_page(): void
    {
        $this->assertContains(SurveyTrendsPage::class, (new SurveysServiceProvider(app()))->registerFilamentPages());
    }

    public function test_needs_the_view_results_permission(): void
    {
        $this->actingAs($this->userWith([]));
        $this->assertFalse(SurveyTrendsPage::canAccess());
        Livewire::test(SurveyTrendsPage::class)->assertForbidden();

        // Managing surveys and templates is not reading their results
        $this->actingAs($this->userWith(['surveys:surveys.manage']));
        Livewire::test(SurveyTrendsPage::class)->assertForbidden();

        $this->actingAs($this->userWith([SurveyTrendsPage::PERMISSION]));
        Livewire::test(SurveyTrendsPage::class)->assertOk();
    }

    public function test_without_template_surveys_it_says_so(): void
    {
        $this->actingAsPanelAdmin();

        Livewire::test(SurveyTrendsPage::class)
            ->assertOk()
            ->assertSee(__('surveys::filament.trends.no_templates'));
    }

    public function test_shows_one_chart_per_keyed_question(): void
    {
        $this->actingAsPanelAdmin();
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3], ['sat' => 4], ['sat' => 5]]);

        Livewire::test(SurveyTrendsPage::class)
            ->assertOk()
            ->assertSet('filters.template', $this->template->value)
            ->assertSee(trans_choice('surveys::filament.trends.responses', 3, ['count' => 3]))
            ->assertSee('¿Te gustó?')
            ->assertSee('¿Qué tal fue? · Ambiente')
            ->assertSee(__('surveys::filament.trends.no_trend'))
            ->assertSeeHtml('question-trend-chart')
            ->assertDontSee('Extra');
    }

    public function test_filters_change_the_cut(): void
    {
        $this->actingAsPanelAdmin();
        $this->answerMany($this->survey(['starts' => '2027-01-10', 'system' => 'Pathfinder']), [['sat' => 3], ['sat' => 4], ['sat' => 5]]);
        $this->answerMany($this->survey(['starts' => '2027-01-11', 'system' => 'Rolemaster']), [['sat' => 3], ['sat' => 3], ['sat' => 3], ['sat' => 3]]);

        Livewire::test(SurveyTrendsPage::class)
            ->assertSee(trans_choice('surveys::filament.trends.responses', 7, ['count' => 7]))
            ->set('filters.system', 'Rolemaster')
            ->assertSee(trans_choice('surveys::filament.trends.responses', 4, ['count' => 4]))
            ->set('filters.from', '2027-02-01')
            ->assertSee(__('surveys::filament.trends.below_minimum'));
    }

    public function test_a_template_from_the_link_and_never_one_without_surveys(): void
    {
        $this->actingAsPanelAdmin();
        $other = $this->saveTemplate($this->definition(), 'Valoración del torneo');
        $unused = $this->saveTemplate($this->definition(), 'Plantilla sin usar');
        $this->survey(['starts' => '2027-01-10']);
        $this->survey(['starts' => '2027-01-10', 'template' => $other]);

        Livewire::withQueryParams(['template' => $other->value])->test(SurveyTrendsPage::class)
            ->assertSet('filters.template', $other->value);

        $page = Livewire::withQueryParams(['template' => $unused->value])->test(SurveyTrendsPage::class)
            ->assertSet('filters.template', $this->template->value)
            ->set('filters.template', $unused->value);
        $instance = $page->instance();
        $this->assertInstanceOf(SurveyTrendsPage::class, $instance);
        $this->assertNull($instance->templateId());
    }

    public function test_the_chart_refuses_to_mount_without_permission(): void
    {
        $this->survey(['starts' => '2027-01-10']);
        $this->actingAs($this->userWith(['surveys:surveys.manage']));

        Livewire::test(QuestionTrendChart::class, ['templateId' => $this->template->value, 'questionKey' => 'satisfaction'])->assertForbidden();
    }

    public function test_the_chart_refuses_a_template_without_surveys(): void
    {
        $this->actingAsPanelAdmin();

        Livewire::test(QuestionTrendChart::class, ['templateId' => $this->template->value, 'questionKey' => 'satisfaction'])->assertForbidden();
    }

    public function test_the_chart_draws_the_mean_per_period(): void
    {
        $this->actingAsPanelAdmin();
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3], ['sat' => 4], ['sat' => 5]]);
        $this->answerMany($this->survey(['starts' => '2027-08-10', 'anonymous' => false]), [['sat' => 1]]);

        $chart = $this->chart('satisfaction');

        $this->assertSame('line', (new ReflectionMethod(QuestionTrendChart::class, 'getType'))->invoke($chart));
        $data = $this->chartData($chart);
        $this->assertSame(['T1 2027', 'T2 2027', 'T3 2027'], data_get($data, 'labels'));
        // T2 has no answers and T3 too few: gaps
        $this->assertSame([4.0, null, null], data_get($data, 'datasets.0.data'));
        $this->assertSame(['min' => 1, 'max' => 5, 'ticks' => ['precision' => 0]], data_get($this->chartOptions($chart), 'scales.y'));
    }

    public function test_the_chart_of_a_ranked_option_puts_the_first_place_on_top(): void
    {
        $this->actingAsPanelAdmin();
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['rank' => ['k-1', 'k-2', 'k-3']], ['rank' => ['k-1', 'k-3', 'k-2']], ['rank' => ['k-2', 'k-1', 'k-3']]]);

        $chart = $this->chart('games.k-1');

        $this->assertSame('line', (new ReflectionMethod(QuestionTrendChart::class, 'getType'))->invoke($chart));
        $this->assertSame([1.33], data_get($this->chartData($chart), 'datasets.0.data'));
        $this->assertSame(['min' => 1, 'max' => 3, 'ticks' => ['precision' => 0], 'reverse' => true], data_get($this->chartOptions($chart), 'scales.y'));
    }

    public function test_the_chart_stacks_shares(): void
    {
        $this->actingAsPanelAdmin();
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['best' => 'o-1'], ['best' => 'o-1'], ['best' => 'o-2']]);

        $chart = $this->chart('best_part');
        $data = $this->chartData($chart);

        $this->assertSame('bar', (new ReflectionMethod(QuestionTrendChart::class, 'getType'))->invoke($chart));
        $this->assertSame(['Historia', 'Combate', 'Otra'], data_get($data, 'datasets.*.label'));
        $this->assertSame([[66.7], [33.3], [0.0]], data_get($data, 'datasets.*.data'));

        $compared = $this->chart('best_part', ['compare' => 'game_system']);
        $this->assertSame(['Rolemaster'], data_get($this->chartData($compared), 'labels'));
        $this->assertSame('y', data_get($this->chartOptions($compared), 'indexAxis'));
    }

    public function test_chart_options_are_never_a_list(): void
    {
        $this->actingAsPanelAdmin();
        $this->answerMany($this->survey(['starts' => '2027-01-10']), [['sat' => 3, 'best' => 'o-1'], ['sat' => 3, 'best' => 'o-1'], ['sat' => 3, 'best' => 'o-1']]);

        foreach (['satisfaction', 'best_part', 'comment', 'missing'] as $key) {
            $options = $this->chartOptions($this->chart($key));
            $this->assertFalse(is_array($options) && array_is_list($options), "The options of {$key} would reach Chart.js as a JSON list");
        }
    }

    public function test_the_template_table_links_to_the_trends(): void
    {
        $this->actingAsPanelAdmin();
        $model = SurveyTemplateModel::query()->findOrFail($this->template->value);

        Livewire::test(ListSurveyTemplates::class)
            ->assertActionVisible(TestAction::make('trends')->table($model))
            ->assertActionHasUrl(TestAction::make('trends')->table($model), SurveyTrendsPage::getUrl(['template' => $this->template->value]));

        $this->actingAs($this->userWith(['surveys:surveys.manage']));
        Livewire::test(ListSurveyTemplates::class)->assertActionHidden(TestAction::make('trends')->table($model));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function chart(string $key, array $filters = []): QuestionTrendChart
    {
        $chart = Livewire::test(QuestionTrendChart::class, ['templateId' => $this->template->value, 'questionKey' => $key, 'filters' => $filters])->instance();
        $this->assertInstanceOf(QuestionTrendChart::class, $chart);

        return $chart;
    }

    private function chartOptions(Component $chart): mixed
    {
        return (new ReflectionMethod(QuestionTrendChart::class, 'getOptions'))->invoke($chart);
    }

    /**
     * @return array<mixed>
     */
    private function chartData(Component $chart): array
    {
        $data = (new ReflectionMethod(QuestionTrendChart::class, 'getCachedData'))->invoke($chart);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'surveys', 'action' => 'view_results', 'module' => 'surveys'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
