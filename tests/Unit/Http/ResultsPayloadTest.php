<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Http;

use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
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
}
