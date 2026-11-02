<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Support;

use Modules\Surveys\Application\DTOs\Results\AvailabilityResultDTO;
use Modules\Surveys\Application\DTOs\Results\AvailabilitySlotResultDTO;
use Modules\Surveys\Application\DTOs\Results\GridResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizQuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuizScoreCountDTO;
use Modules\Surveys\Application\DTOs\Results\QuizStandingDTO;
use Modules\Surveys\Application\DTOs\Results\RankedOptionDTO;
use Modules\Surveys\Application\DTOs\Results\RankingResultDTO;
use Modules\Surveys\Application\DTOs\Results\ResultBucketDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Application\DTOs\StoredAnswerFile;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\FileKind;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Infrastructure\Files\AnswerFileLinks;

/**
 * A survey's results as the public site's pages receive them. Respondents get the counts;
 * the organizer also gets free text, each question's chart choices and a quiz's scores.
 */
final class ResultsPayload
{
    /**
     * @return array{responses: int, questions: list<array<string, mixed>>}
     */
    public static function forRespondents(SurveyResultsDTO $results, bool $withVoters): array
    {
        return [
            'responses' => $results->responses,
            'questions' => array_map(static fn (QuestionResultDTO $question): array => self::question($question, $withVoters), $results->questions),
        ];
    }

    /**
     * @return array{visible: bool, hiddenReason: ?string, responses: int, audienceSize: int, rate: ?float, questions: list<array<string, mixed>>, quiz: ?array<string, mixed>}
     */
    public static function forOrganizer(SurveyResultsDTO $results): array
    {
        return [
            'visible' => $results->visible,
            'hiddenReason' => $results->hiddenReason,
            'responses' => $results->responses,
            'audienceSize' => $results->audienceSize,
            'rate' => $results->rate,
            'questions' => array_map(static fn (QuestionResultDTO $question): array => [
                ...self::question($question, withVoters: true),
                'chartType' => $question->chartType->value,
                'chartTypes' => array_map(static fn (ChartType $type): string => $type->value, $question->type->chartTypes()),
                'texts' => $question->texts,
                // Images get a preview that opens in the page; every file a download link
                'files' => array_map(static fn (StoredAnswerFile $file): array => [
                    'id' => $file->id,
                    'name' => AnswerFileLinks::downloadName($file),
                    'contentType' => $file->contentType,
                    'size' => $file->size,
                    'url' => AnswerFileLinks::forPage($file),
                    'previewUrl' => FileKind::forContentType($file->contentType) === FileKind::Image ? AnswerFileLinks::forPage($file, inline: true) : null,
                ], $question->files),
                'quiz' => $question->quiz instanceof QuizQuestionResultDTO ? [
                    'correctOptionIds' => $question->quiz->correctOptionIds,
                    'points' => $question->quiz->points,
                    'correct' => $question->quiz->correct,
                    'rate' => $question->quiz->rate,
                ] : null,
            ], $results->questions),
            'quiz' => $results->quiz instanceof QuizResultDTO ? self::quiz($results->quiz) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function quiz(QuizResultDTO $quiz): array
    {
        return [
            'maxPoints' => $quiz->maxPoints,
            'mean' => $quiz->mean,
            'belowMinimum' => $quiz->belowMinimum,
            'distribution' => array_map(static fn (QuizScoreCountDTO $count): array => ['points' => $count->points, 'responses' => $count->responses], $quiz->distribution),
            'leaderboard' => $quiz->leaderboard === null ? null : array_map(
                static fn (QuizStandingDTO $standing): array => ['name' => $standing->name, 'points' => $standing->points, 'rank' => $standing->rank],
                $quiz->leaderboard,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function question(QuestionResultDTO $question, bool $withVoters): array
    {
        return [
            'id' => $question->questionId,
            'title' => $question->title,
            'type' => $question->type->value,
            'answered' => $question->answered,
            'belowMinimum' => $question->belowMinimum,
            'reached' => $question->reached,
            'buckets' => array_map(static fn (ResultBucketDTO $bucket): array => [
                'key' => $bucket->key,
                'label' => $bucket->label,
                'count' => $bucket->count,
                'voters' => $withVoters ? $bucket->voters : [],
            ], $question->buckets),
            'grid' => $question->grid instanceof GridResultDTO ? [
                'rows' => array_map(static fn (Option $row): array => $row->toArray(), $question->grid->rows),
                'columns' => array_map(static fn (Option $column): array => $column->toArray(), $question->grid->columns),
                'counts' => $question->grid->counts,
            ] : null,
            'mean' => $question->mean,
            'median' => $question->median,
            // Places as a list, the first place first: a map keyed 1, 2… would reach Vue as an object
            'ranking' => $question->ranking instanceof RankingResultDTO ? [
                'positions' => $question->ranking->positions,
                'options' => array_map(static fn (RankedOptionDTO $option): array => [
                    'id' => $option->optionId,
                    'label' => $option->label,
                    'points' => $option->points,
                    'meanPosition' => $option->meanPosition,
                    'places' => array_values($option->positions),
                ], $question->ranking->options),
            ] : null,
            'availability' => $question->availability instanceof AvailabilityResultDTO ? [
                'chosenSlotId' => $question->availability->chosenSlotId,
                'slots' => array_map(static fn (AvailabilitySlotResultDTO $slot): array => [
                    'id' => $slot->slotId,
                    'label' => $slot->label,
                    'shortLabel' => $slot->shortLabel,
                    'yes' => $slot->yes,
                    'ifNeeded' => $slot->ifNeeded,
                    'no' => $slot->no,
                    'best' => $slot->best,
                    'yesNames' => $withVoters ? $slot->yesNames : [],
                    'ifNeededNames' => $withVoters ? $slot->ifNeededNames : [],
                    'noNames' => $withVoters ? $slot->noNames : [],
                ], $question->availability->slots),
            ] : null,
        ];
    }
}
