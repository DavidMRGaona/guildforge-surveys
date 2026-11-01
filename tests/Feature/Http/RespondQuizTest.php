<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\Services\QuizDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class RespondQuizTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->player = UserModel::factory()->create();
    }

    public function test_whoever_answers_never_gets_the_correct_options_or_points(): void
    {
        $id = $this->openQuiz();

        $this->actingAs($this->player)->get(route('surveys.respond', $id->value))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('definition.quiz.show_correct_answers', true)
                ->where('definition.sections.0.questions.0.correct_option_ids', [])
                ->where('definition.sections.0.questions.0.points', null)
                ->where('definition.sections.0.questions.1.correct_option_ids', [])
                ->missing('quiz'));
    }

    public function test_after_sending_they_see_their_score_and_what_they_got_right(): void
    {
        $id = $this->openQuiz();

        $this->actingAs($this->player)
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-capital' => 'o-b', 'q-colours' => ['o-blue']]])
            ->assertRedirect(route('surveys.respond', $id->value));

        $this->actingAs($this->player)->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', 'answered')
                ->where('quiz.points', 2)
                ->where('quiz.maxPoints', 5)
                ->where('quiz.questions.0', ['id' => 'q-capital', 'title' => 'Pregunta q-capital', 'correct' => true, 'points' => 2, 'correctAnswers' => ['París']])
                ->where('quiz.questions.1', ['id' => 'q-colours', 'title' => 'Pregunta q-colours', 'correct' => false, 'points' => 3, 'correctAnswers' => ['Azul', 'Rojo']])
                ->count('quiz.questions', 2));

        // Only right after sending: coming back later shows it answered, with no score
        $this->actingAs($this->player)->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'answered')->missing('quiz'));
    }

    public function test_without_showing_the_answers_they_only_see_their_score(): void
    {
        $id = $this->openQuiz(showCorrectAnswers: false);

        $this->actingAs($this->player)
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-capital' => 'o-a', 'q-colours' => ['o-blue', 'o-red']]]);

        $this->actingAs($this->player)->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page
                ->where('quiz.points', 3)
                ->where('quiz.maxPoints', 5)
                ->where('quiz.questions', null));
    }

    public function test_a_survey_that_is_not_a_quiz_has_no_score(): void
    {
        $data = QuizDefinitionTest::data();
        $data['quiz'] = null;
        foreach (array_keys($data['sections'][0]['questions']) as $q) {
            $data['sections'][0]['questions'][$q]['correct_option_ids'] = [];
            $data['sections'][0]['questions'][$q]['points'] = null;
        }
        $id = $this->open(SurveyDefinition::fromArray($data));

        $this->actingAs($this->player)
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-capital' => 'o-b']]);

        $this->actingAs($this->player)->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'answered')->missing('quiz'));
    }

    private function openQuiz(bool $showCorrectAnswers = true): SurveyId
    {
        $data = QuizDefinitionTest::data();
        $data['quiz'] = ['show_correct_answers' => $showCorrectAnswers];

        return $this->open(SurveyDefinition::fromArray($data));
    }

    private function open(SurveyDefinition $definition): SurveyId
    {
        ExampleThingSource::$audience = new SurveyAudienceDTO([$this->player->id], []);
        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $id = $lifecycle->createForSubject(
            ExampleThingSource::TYPE,
            'thing-1',
            'Cuestionario de ejemplo',
            $definition,
            anonymous: true,
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
        );
        $lifecycle->open($id);

        return $id;
    }
}
