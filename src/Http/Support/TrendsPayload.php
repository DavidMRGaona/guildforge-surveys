<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Support;

use Modules\Surveys\Application\DTOs\Trends\QuestionTrendDTO;
use Modules\Surveys\Application\DTOs\Trends\TemplateTrendsDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendPointDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendSeriesDTO;

/**
 * A template's trends as the site's trends page reads them. Series go by label only: the
 * site never needs an id to tell them apart.
 */
final class TrendsPayload
{
    /**
     * @return array{visible: bool, surveys: int, responses: int, questions: list<array<string, mixed>>}
     */
    public static function from(TemplateTrendsDTO $trends): array
    {
        return [
            'visible' => $trends->visible,
            'surveys' => $trends->surveys,
            'responses' => $trends->responses,
            'questions' => array_map(self::question(...), $trends->questions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function question(QuestionTrendDTO $question): array
    {
        return [
            'key' => $question->key,
            'title' => $question->title,
            'type' => $question->type->value,
            'measure' => $question->measure,
            'min' => $question->min,
            'max' => $question->max,
            'periods' => $question->periods,
            'options' => $question->options,
            'series' => array_map(static fn (TrendSeriesDTO $series): array => [
                'label' => $series->label,
                'points' => array_map(static fn (TrendPointDTO $point): array => [
                    'period' => $point->period,
                    'answered' => $point->answered,
                    'belowMinimum' => $point->belowMinimum,
                    'mean' => $point->mean,
                    'shares' => $point->shares,
                ], $series->points),
            ], $question->series),
        ];
    }
}
