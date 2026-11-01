<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizQuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizScoreCountDTO;
use Modules\Surveys\Application\DTOs\Results\QuizStandingDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Unit\Domain\Services\QuizDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class QuizResultsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    use AnswersExampleSurveys;

    private ResultsService $results;

    protected function setUp(): void
    {
        parent::setUp();

        $this->results = $this->app->make(ResultsService::class);
        $this->createExamplePeople();
    }

    public function test_the_organizer_sees_the_scores_their_spread_and_who_scored_what(): void
    {
        $id = $this->openQuiz(anonymous: false);
        $this->answerThree($id);

        $quiz = $this->quiz($this->results->forPanel($id));

        $this->assertSame(5, $quiz->maxPoints);
        $this->assertSame(2.33, $quiz->mean);
        $this->assertFalse($quiz->belowMinimum);
        $this->assertSame([[0, 1], [2, 1], [5, 1]], array_map(static fn (QuizScoreCountDTO $count): array => [$count->points, $count->responses], $quiz->distribution));
        $this->assertSame(
            [['Persona A', 5, 1], ['Persona B', 2, 2], ['Persona C', 0, 3]],
            array_map(static fn (QuizStandingDTO $standing): array => [$standing->name, $standing->points, $standing->rank], $quiz->leaderboard ?? []),
        );
    }

    public function test_each_scored_question_says_how_many_got_it_right_among_who_answered(): void
    {
        $id = $this->openQuiz(anonymous: false);
        $this->answerThree($id);

        $questions = $this->results->forPanel($id)->questions;

        $this->assertEquals(new QuizQuestionResultDTO(['o-b'], 2, 2, 0.6667), $this->scored($questions, 'q-capital'));
        $this->assertEquals(new QuizQuestionResultDTO(['o-blue', 'o-red'], 3, 1, 0.3333), $this->scored($questions, 'q-colours'));
        $this->assertNull($this->scored($questions, 'q-notes'));
    }

    public function test_people_on_the_same_score_share_their_place(): void
    {
        $id = $this->openQuiz(anonymous: false);
        $this->answer($id, 0, ['q-capital' => 'o-b', 'q-colours' => ['o-blue', 'o-red']]);
        $this->answer($id, 1, ['q-capital' => 'o-a', 'q-colours' => ['o-blue']]);
        $this->answer($id, 2, ['q-capital' => 'o-b', 'q-colours' => ['o-red', 'o-blue']]);

        $leaderboard = $this->quiz($this->results->forOrganizer($id))->leaderboard ?? [];

        $this->assertSame(
            [['Persona A', 5, 1], ['Persona C', 5, 1], ['Persona B', 0, 3]],
            array_map(static fn (QuizStandingDTO $standing): array => [$standing->name, $standing->points, $standing->rank], $leaderboard),
        );
    }

    public function test_an_anonymous_quiz_shows_the_spread_but_nobody_by_name(): void
    {
        $id = $this->openQuiz(anonymous: true, live: true);
        $this->answerThree($id);

        $quiz = $this->quiz($this->results->forPanel($id));

        $this->assertNull($quiz->leaderboard);
        $this->assertSame(2.33, $quiz->mean);
        $this->assertCount(3, $quiz->distribution);
    }

    public function test_an_anonymous_quiz_hides_its_scores_while_a_scored_question_is_below_the_minimum(): void
    {
        $id = $this->openQuiz(anonymous: true, live: true);
        // q-colours is optional and only two people answer it: under the minimum of three
        $this->answer($id, 0, ['q-capital' => 'o-b', 'q-colours' => ['o-blue', 'o-red']]);
        $this->answer($id, 1, ['q-capital' => 'o-b', 'q-colours' => ['o-blue']]);
        $this->answer($id, 2, ['q-capital' => 'o-a']);

        $quiz = $this->quiz($this->results->forPanel($id));

        $this->assertTrue($quiz->belowMinimum);
        $this->assertNull($quiz->mean);
        $this->assertSame([], $quiz->distribution);
    }

    public function test_respondents_never_see_scores_or_correct_answers(): void
    {
        $id = $this->openQuiz(anonymous: false, shared: true);
        $this->answerThree($id);

        $results = $this->results->forRespondents($id);

        $this->assertTrue($results->visible);
        $this->assertNull($results->quiz);
        $this->assertNull($this->scored($results->questions, 'q-capital'));
    }

    public function test_a_survey_that_is_not_a_quiz_has_no_scores(): void
    {
        [$id] = $this->openForm(anonymous: false);
        $this->answer($id, 0);

        $this->assertNull($this->results->forPanel($id)->quiz);
    }

    private function answerThree(SurveyId $id): void
    {
        $this->answer($id, 0, ['q-capital' => 'o-b', 'q-colours' => ['o-blue', 'o-red'], 'q-notes' => 'Fácil']);
        $this->answer($id, 1, ['q-capital' => 'o-b', 'q-colours' => ['o-blue']]);
        $this->answer($id, 2, ['q-capital' => 'o-a', 'q-colours' => ['o-green']]);
    }

    private function openQuiz(bool $anonymous, bool $live = false, bool $shared = false): SurveyId
    {
        $defaults = ResultsSettings::defaultsFor(SurveyKind::Form);
        $settings = new ResultsSettings(
            liveResults: $live || $shared,
            visibleToRespondents: $shared,
            chartTypes: $defaults->chartTypes,
            hiddenQuestionIds: $defaults->hiddenQuestionIds,
        );

        return $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(QuizDefinitionTest::data()), $anonymous, $settings)[0];
    }

    private function quiz(SurveyResultsDTO $results): QuizResultDTO
    {
        $this->assertTrue($results->visible);
        $this->assertInstanceOf(QuizResultDTO::class, $results->quiz);

        return $results->quiz;
    }

    /**
     * @param  list<QuestionResultDTO>  $questions
     */
    private function scored(array $questions, string $questionId): ?QuizQuestionResultDTO
    {
        foreach ($questions as $question) {
            if ($question->questionId === $questionId) {
                return $question->quiz;
            }
        }

        return null;
    }
}
