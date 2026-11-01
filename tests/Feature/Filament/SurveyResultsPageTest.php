<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Component;
use Livewire\Livewire;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurveyResults;
use Modules\Surveys\Filament\Widgets\QuestionResultChart;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use ReflectionMethod;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyResultsPageTest extends ModuleTestCase
{
    use AnswersExampleSurveys;
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->createExamplePeople();
    }

    public function test_shows_each_question_with_its_chart_or_its_texts(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-short' => 'Una partida genial']);

        Livewire::test(ViewSurveyResults::class, ['record' => $id->value])
            ->assertOk()
            ->assertSee(trans_choice('surveys::filament.results.responses', 1, ['count' => 1]))
            ->assertSee('Una partida genial')
            ->assertSee('Pregunta q-single');
    }

    public function test_says_how_many_reached_a_question_some_sections_skipped(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition()), false, ResultsSettings::defaultsFor(SurveyKind::Form));
        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($id, Respondent::account($this->people[0]->id), ['q-played' => 'o-no', 'q-comments' => 'Bien']);
        $submissions->submit($id, Respondent::account($this->people[1]->id), ['q-played' => 'o-yes', 'q-finale' => 'Épico', 'q-comments' => 'Bien']);

        Livewire::test(ViewSurveyResults::class, ['record' => $id->value])
            ->assertOk()
            ->assertSee(__('surveys::filament.results.reached', ['reached' => 1, 'responses' => 2]));
    }

    public function test_an_anonymous_survey_below_the_minimum_shows_only_the_notice(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: true);
        $this->answer($id, 0, ['q-short' => 'Nadie debe leer esto']);
        $this->app->make(SurveyLifecycleService::class)->close($id);

        Livewire::test(ViewSurveyResults::class, ['record' => $id->value])
            ->assertSee(__('surveys::filament.results.hidden.below_minimum'))
            ->assertDontSee('Nadie debe leer esto')
            ->assertDontSee('Pregunta q-single');
    }

    public function test_needs_the_results_permission(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->actingAs(UserModel::factory()->editor()->create());

        Livewire::test(ViewSurveyResults::class, ['record' => $id->value])->assertForbidden();
        Livewire::test(QuestionResultChart::class, ['surveyId' => $id->value, 'questionId' => 'q-single'])->assertForbidden();
    }

    public function test_the_chart_draws_the_counts_of_its_question(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-single' => 'o-1']);
        $this->answer($id, 1, ['q-single' => ['options' => [Submission::OTHER], 'other' => 'Otra cosa']]);

        $data = $this->chartData(Livewire::test(QuestionResultChart::class, ['surveyId' => $id->value, 'questionId' => 'q-single'])->instance());

        $this->assertSame(['Uno', 'Dos', __('surveys::filament.results.other')], data_get($data, 'labels'));
        $this->assertSame([1, 0, 1], data_get($data, 'datasets.0.data'));
    }

    public function test_the_chart_draws_nothing_while_the_results_are_hidden(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: true);
        $this->answer($id, 0, ['q-single' => 'o-1']);

        $data = $this->chartData(Livewire::test(QuestionResultChart::class, ['surveyId' => $id->value, 'questionId' => 'q-single'])->instance());

        $this->assertSame([], data_get($data, 'datasets'));
    }

    public function test_configuring_the_results_saves_chart_types_and_hidden_questions(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: false);

        Livewire::test(ViewSurveyResults::class, ['record' => $id->value])
            ->callAction('configure_results', data: ['chart_types' => ['q-single' => 'pie'], 'hidden_question_ids' => ['q-dropdown']])
            ->assertHasNoActionErrors();

        $results = $this->app->make(SurveyRepositoryInterface::class)->findOrFail($id)->results();
        $this->assertSame(ChartType::Pie->value, $results->chartTypes['q-single']);
        $this->assertSame(['q-dropdown'], $results->hiddenQuestionIds);
    }

    public function test_a_pie_chart_hands_chart_js_an_options_object(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0, ['q-single' => 'o-1']);
        Livewire::test(ViewSurveyResults::class, ['record' => $id->value])
            ->callAction('configure_results', data: ['chart_types' => ['q-single' => 'pie'], 'hidden_question_ids' => []])
            ->assertHasNoActionErrors();

        $chart = Livewire::test(QuestionResultChart::class, ['surveyId' => $id->value, 'questionId' => 'q-single']);

        $this->assertSame('pie', (new ReflectionMethod(QuestionResultChart::class, 'getType'))->invoke($chart->instance()));
        $this->assertNotJsonList($this->chartOptions($chart->instance()));
        $chart->assertDontSeeHtml('options: []');
    }

    public function test_a_chart_without_a_result_hands_chart_js_an_options_object(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: false);

        $chart = Livewire::test(QuestionResultChart::class, ['surveyId' => $id->value, 'questionId' => 'q-missing']);

        $this->assertNotJsonList($this->chartOptions($chart->instance()));
        $chart->assertDontSeeHtml('options: []');
    }

    /**
     * Chart.js reads its options as an object: an empty PHP array reaches it as the JSON list
     * [] and breaks the chart, while null becomes {} in Filament's widget.
     */
    private function assertNotJsonList(mixed $options): void
    {
        $this->assertFalse(is_array($options) && array_is_list($options), 'The chart options would reach Chart.js as a JSON list');
    }

    private function chartOptions(Component $chart): mixed
    {
        $this->assertInstanceOf(QuestionResultChart::class, $chart);

        return (new ReflectionMethod(QuestionResultChart::class, 'getOptions'))->invoke($chart);
    }

    /**
     * What the chart hands to Chart.js; getCachedData() is protected on Filament's ChartWidget.
     *
     * @return array<mixed>
     */
    private function chartData(Component $chart): array
    {
        $this->assertInstanceOf(QuestionResultChart::class, $chart);
        $data = (new ReflectionMethod(QuestionResultChart::class, 'getCachedData'))->invoke($chart);
        $this->assertIsArray($data);

        return $data;
    }
}
