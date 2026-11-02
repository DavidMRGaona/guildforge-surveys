<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use Modules\Surveys\Application\Contracts\SurveyResultsQueryInterface;
use Modules\Surveys\Application\DTOs\Results\QuizResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizScoreCountDTO;
use Modules\Surveys\Application\DTOs\Results\QuizStandingDTO;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Services\QuizScorer;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\QuizSettings;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;

/**
 * Scores every response to a quiz again from its stored answers (scores are never stored).
 */
final readonly class QuizResultsCalculator
{
    public function __construct(
        private SurveyResultsQueryInterface $query,
    ) {}

    /**
     * The quiz as a whole and, per scored question, how many responses got it right. On an
     * anonymous survey ($minimum > 0) the scores stay hidden while a scored question has fewer
     * verified answers than the minimum: a total would give that question's answers away.
     *
     * @return array{QuizResultDTO, array<string, int>}|null null when the survey is not a quiz
     */
    public function calculate(Survey $survey, int $minimum): ?array
    {
        $definition = $survey->definition();
        if (! $definition->quiz instanceof QuizSettings) {
            return null;
        }

        $scorer = new QuizScorer;
        $scored = $scorer->scoredQuestions($definition);
        $ids = array_map(static fn (Question $question): string => $question->id, $scored);
        $maxPoints = $scorer->maxPoints($definition);

        $correct = array_fill_keys($ids, 0);
        $scores = [];
        $standings = [];
        foreach ($this->query->responseValues($survey->id(), $ids) as $response) {
            $submission = new Submission($response['values']);
            $points = 0;
            foreach ($scored as $question) {
                if ($scorer->isCorrect($question, $submission->valuesFor($question->id))) {
                    $correct[$question->id]++;
                    $points += (int) $question->points;
                }
            }

            $scores[] = $points;
            if ($response['name'] !== null) {
                $standings[] = [$response['name'], $points];
            }
        }

        if ($minimum > 0) {
            $verified = $this->query->verifiedAnsweredCounts($survey->id(), $ids);
            if (array_any($ids, static fn (string $id): bool => ($verified[$id] ?? 0) < $minimum)) {
                return [new QuizResultDTO($maxPoints, belowMinimum: true), $correct];
            }
        }

        return [new QuizResultDTO(
            maxPoints: $maxPoints,
            mean: $scores === [] ? null : round(array_sum($scores) / count($scores), 2),
            distribution: $this->distribution($scores),
            leaderboard: $survey->isAnonymous() ? null : $this->leaderboard($standings),
        ), $correct];
    }

    /**
     * @param  list<int>  $scores
     * @return list<QuizScoreCountDTO>
     */
    private function distribution(array $scores): array
    {
        $counts = array_count_values($scores);
        ksort($counts);

        return array_map(static fn (int $points, int $responses): QuizScoreCountDTO => new QuizScoreCountDTO($points, $responses), array_keys($counts), array_values($counts));
    }

    /**
     * Highest first, then by name; people on the same score share the place of the first of them.
     *
     * @param  list<array{string, int}>  $standings
     * @return list<QuizStandingDTO>
     */
    private function leaderboard(array $standings): array
    {
        usort($standings, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: strnatcasecmp($a[0], $b[0]));

        $leaderboard = [];
        foreach ($standings as $position => [$name, $points]) {
            $previous = $leaderboard[$position - 1] ?? null;
            $rank = $previous instanceof QuizStandingDTO && $previous->points === $points ? $previous->rank : $position + 1;
            $leaderboard[] = new QuizStandingDTO($name, $points, $rank);
        }

        return $leaderboard;
    }
}
