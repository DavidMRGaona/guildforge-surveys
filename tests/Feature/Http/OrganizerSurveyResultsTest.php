<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

final class OrganizerSurveyResultsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    /** @var list<UserModel> */
    private array $players;

    private SurveyRepositoryInterface $surveys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);

        $this->organizer = UserModel::factory()->create();
        $this->players = [UserModel::factory()->create(), UserModel::factory()->create(), UserModel::factory()->create()];
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO(array_map(static fn (UserModel $player): string => $player->id, $this->players));
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-05T20:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_organizer_sees_the_results_of_a_named_survey_with_its_rate(): void
    {
        $survey = $this->opened(anonymous: false);
        $this->answer($survey, 0, 'si');

        $this->actingAs($this->organizer)->get(route('surveys.manage.results', $survey->id()->value))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Results', false)
                ->where('results.visible', true)
                ->where('results.responses', 1)
                ->where('results.audienceSize', 3)
                ->where('results.questions.0.id', 'q-single')
                ->where('results.questions.0.chartType', 'bar')
                ->where('results.questions.0.chartTypes', ['bar', 'pie'])
                ->where('results.questions.1.texts', ['Muy bien'])
                ->where('settingsUrl', route('surveys.manage.results.update', $survey->id()->value)));
    }

    public function test_an_anonymous_open_survey_waits_until_it_closes(): void
    {
        $survey = $this->opened(anonymous: true);
        foreach ([0, 1, 2] as $player) {
            $this->answer($survey, $player, 'si');
        }

        $this->actingAs($this->organizer)->get(route('surveys.manage.results', $survey->id()->value))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.visible', false)
                ->where('results.hiddenReason', 'not_closed')
                ->where('results.questions', []));
    }

    public function test_strangers_cannot_see_or_configure_the_results(): void
    {
        $survey = $this->opened(anonymous: false);
        $stranger = UserModel::factory()->create();

        $this->actingAs($stranger)->get(route('surveys.manage.results', $survey->id()->value))->assertForbidden();
        $this->actingAs($stranger)->put(route('surveys.manage.results.update', $survey->id()->value), ['chart_types' => [], 'hidden_question_ids' => []])->assertForbidden();
    }

    public function test_a_survey_never_sent_has_no_results_page_yet(): void
    {
        $survey = $this->offered(anonymous: false);

        $this->actingAs($this->organizer)->get(route('surveys.manage.results', $survey->id()->value))->assertNotFound();
    }

    public function test_the_organizer_picks_charts_and_hides_questions(): void
    {
        $survey = $this->opened(anonymous: false);

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.results.update', $survey->id()->value), [
                'chart_types' => ['q-single' => 'pie', 'q-text' => 'pie', 'unknown' => 'bar'],
                'hidden_question_ids' => ['q-text', 'unknown'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success', __('surveys::messages.manage.results_saved'));

        $results = $this->surveys->findOrFail($survey->id())->results();
        // Only a chart the question can draw is kept, and only questions it has
        $this->assertSame(['q-single' => 'pie'], $results->chartTypes);
        $this->assertSame(['q-text'], $results->hiddenQuestionIds);
        $this->assertFalse($results->liveResults);
    }

    public function test_a_question_left_out_of_the_request_keeps_its_chart(): void
    {
        $survey = $this->opened(anonymous: false);
        $this->actingAs($this->organizer)->put(route('surveys.manage.results.update', $survey->id()->value), [
            'chart_types' => ['q-single' => 'pie'],
            'hidden_question_ids' => [],
        ]);

        // Hidden now, so the page no longer sends its chart
        $this->actingAs($this->organizer)->put(route('surveys.manage.results.update', $survey->id()->value), [
            'chart_types' => [],
            'hidden_question_ids' => ['q-single'],
        ]);

        $results = $this->surveys->findOrFail($survey->id())->results();
        $this->assertSame(['q-single' => 'pie'], $results->chartTypes);
        $this->assertSame(['q-single'], $results->hiddenQuestionIds);
    }

    public function test_hidden_questions_are_left_out_and_listed_to_show_them_again(): void
    {
        $survey = $this->opened(anonymous: false);
        $this->actingAs($this->organizer)->put(route('surveys.manage.results.update', $survey->id()->value), [
            'hidden_question_ids' => ['q-text'],
        ]);

        $this->actingAs($this->organizer)->get(route('surveys.manage.results', $survey->id()->value))
            ->assertInertia(fn (Assert $page) => $page
                ->has('results.questions', 1)
                ->where('results.questions.0.id', 'q-single')
                ->where('hiddenQuestions', [['id' => 'q-text', 'title' => 'Comentarios']]));
    }

    private function answer(Survey $survey, int $player, string $option): void
    {
        $this->app->make(SubmissionService::class)->submit(
            $survey->id(),
            Respondent::account($this->players[$player]->id),
            ['q-single' => $option, 'q-text' => 'Muy bien'],
        );
    }

    private function opened(bool $anonymous): Survey
    {
        $survey = $this->offered($anonymous);
        $this->app->make(SurveyLifecycleService::class)->open($survey->id());

        return $this->surveys->findOrFail($survey->id());
    }

    private function offered(bool $anonymous): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(['sections' => [[
                'id' => 's-1',
                'title' => null,
                'description' => null,
                'questions' => [
                    [
                        'id' => 'q-single', 'key' => null, 'type' => 'single_choice', 'title' => '¿Repetirías?', 'help' => null, 'required' => true,
                        'options' => [['id' => 'si', 'label' => 'Sí'], ['id' => 'no', 'label' => 'No']],
                        'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null,
                    ],
                    [
                        'id' => 'q-text', 'key' => null, 'type' => 'paragraph', 'title' => 'Comentarios', 'help' => null, 'required' => false,
                        'options' => [], 'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null,
                    ],
                ],
            ]]]),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: $anonymous, durationDays: 7, subjectEndKey: null);
    }
}
