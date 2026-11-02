<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Http;

use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizQuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizScoreCountDTO;
use Modules\Surveys\Application\DTOs\Results\QuizStandingDTO;
use Modules\Surveys\Application\DTOs\Results\RankedOptionDTO;
use Modules\Surveys\Application\DTOs\Results\RankingResultDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Http\Support\ResultsPayload;
use PHPUnit\Framework\TestCase;

final class ResultsPayloadTest extends TestCase
{
    public function test_a_ranking_sends_each_option_with_its_points_mean_place_and_places_in_order(): void
    {
        $ranking = new RankingResultDTO(3, [
            new RankedOptionDTO('k-2', 'Wargames', 8, 1.33, [1 => 2, 2 => 1, 3 => 0]),
            new RankedOptionDTO('k-1', 'Rol', 6, 2.0, [1 => 1, 2 => 1, 3 => 1]),
        ]);
        $results = new SurveyResultsDTO(3, 3, 1.0, true, null, [
            new QuestionResultDTO('q-rank', 'Ordena', QuestionType::Ranking, ChartType::Bar, false, 3, ranking: $ranking),
            new QuestionResultDTO('q-short', 'Nombre', QuestionType::ShortText, ChartType::List, false, 3),
        ]);

        $question = ResultsPayload::forRespondents($results, withVoters: false)['questions'][0];

        $this->assertSame([
            'positions' => 3,
            'options' => [
                ['id' => 'k-2', 'label' => 'Wargames', 'points' => 8, 'meanPosition' => 1.33, 'places' => [2, 1, 0]],
                ['id' => 'k-1', 'label' => 'Rol', 'points' => 6, 'meanPosition' => 2.0, 'places' => [1, 1, 1]],
            ],
        ], $question['ranking']);
        $this->assertNull(ResultsPayload::forOrganizer($results)['questions'][1]['ranking']);
    }

    public function test_the_organizer_gets_a_quiz_scores_and_each_question_how_it_went_but_respondents_do_not(): void
    {
        $quiz = new QuizResultDTO(5, 2.5, [new QuizScoreCountDTO(0, 1), new QuizScoreCountDTO(5, 1)], [new QuizStandingDTO('Persona A', 5, 1), new QuizStandingDTO('Persona B', 0, 2)]);
        $results = new SurveyResultsDTO(2, 2, 1.0, true, null, [
            new QuestionResultDTO('q-capital', 'Capital', QuestionType::SingleChoice, ChartType::Bar, false, 2, quiz: new QuizQuestionResultDTO(['o-b'], 2, 1, 0.5)),
            new QuestionResultDTO('q-notes', 'Notas', QuestionType::ShortText, ChartType::List, false, 2),
        ], $quiz);

        $organizer = ResultsPayload::forOrganizer($results);

        $this->assertSame([
            'maxPoints' => 5,
            'mean' => 2.5,
            'belowMinimum' => false,
            'distribution' => [['points' => 0, 'responses' => 1], ['points' => 5, 'responses' => 1]],
            'leaderboard' => [['name' => 'Persona A', 'points' => 5, 'rank' => 1], ['name' => 'Persona B', 'points' => 0, 'rank' => 2]],
        ], $organizer['quiz']);
        $this->assertSame(['correctOptionIds' => ['o-b'], 'points' => 2, 'correct' => 1, 'rate' => 0.5], $organizer['questions'][0]['quiz']);
        $this->assertNull($organizer['questions'][1]['quiz']);
        $this->assertArrayNotHasKey('quiz', ResultsPayload::forRespondents($results, withVoters: false));
        $this->assertArrayNotHasKey('quiz', ResultsPayload::forRespondents($results, withVoters: false)['questions'][0]);
    }
}
