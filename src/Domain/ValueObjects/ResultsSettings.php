<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects;

use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;

/**
 * How a survey shows its results: live (before closing, which weakens anonymity), to the
 * people who answer, with a chart type per question and some questions hidden.
 */
final readonly class ResultsSettings
{
    /**
     * @param  array<string, string>  $chartTypes  question id => chart type
     * @param  list<string>  $hiddenQuestionIds
     */
    public function __construct(
        public bool $liveResults = false,
        public bool $visibleToRespondents = false,
        public array $chartTypes = [],
        public array $hiddenQuestionIds = [],
    ) {}

    /**
     * Polls show their results live to whoever votes, as in messaging apps; forms do not.
     */
    public static function defaultsFor(SurveyKind $kind): self
    {
        $isPoll = $kind === SurveyKind::Poll;

        return new self(liveResults: $isPoll, visibleToRespondents: $isPoll);
    }

    /**
     * The chart chosen for the question, or its default when none was chosen or it does not fit.
     */
    public function chartTypeFor(Question $question): ChartType
    {
        $chosen = ChartType::tryFrom($this->chartTypes[$question->id] ?? '');

        return $chosen instanceof ChartType && in_array($chosen, $question->type->chartTypes(), true)
            ? $chosen
            : $question->type->defaultChartType();
    }

    public function isHidden(string $questionId): bool
    {
        return in_array($questionId, $this->hiddenQuestionIds, true);
    }

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $chartTypes = is_array($data['chart_types'] ?? null) ? $data['chart_types'] : [];
        $hidden = is_array($data['hidden_question_ids'] ?? null) ? $data['hidden_question_ids'] : [];

        return new self(
            liveResults: ($data['live_results'] ?? false) === true,
            visibleToRespondents: ($data['visible_to_respondents'] ?? false) === true,
            chartTypes: array_filter(
                $chartTypes,
                static fn (mixed $type, mixed $questionId): bool => is_string($type) && is_string($questionId),
                ARRAY_FILTER_USE_BOTH,
            ),
            hiddenQuestionIds: array_values(array_filter($hidden, is_string(...))),
        );
    }

    /**
     * @return array{live_results: bool, visible_to_respondents: bool, chart_types: array<string, string>, hidden_question_ids: list<string>}
     */
    public function toArray(): array
    {
        return [
            'live_results' => $this->liveResults,
            'visible_to_respondents' => $this->visibleToRespondents,
            'chart_types' => $this->chartTypes,
            'hidden_question_ids' => $this->hiddenQuestionIds,
        ];
    }
}
