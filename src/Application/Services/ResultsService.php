<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Modules\Surveys\Application\Contracts\SurveyResultsQueryInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\Results\GridResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\ResultBucketDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\GridSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * A survey's results, and the one rule on when they may be seen.
 *
 * An anonymous survey shows nothing before it ends (comparing results before and after a response
 * would give the response away) unless it has live results, and never below the minimum number
 * of responses. A named survey has nothing to protect in the panel.
 */
final readonly class ResultsService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyResultsQueryInterface $query,
        private SurveySettingsInterface $settings,
    ) {}

    /**
     * What the panel shows, hidden questions included and marked.
     *
     * @param  list<string>|null  $questionIds  only these questions (a chart widget needs one)
     */
    public function forPanel(SurveyId $id, ?array $questionIds = null, ?DateTimeImmutable $now = null): SurveyResultsDTO
    {
        $survey = $this->surveys->findOrFail($id);
        $responses = $this->query->responseCount($id);
        $reason = $this->panelHiddenReason($survey, $responses, $now ?? now()->toDateTimeImmutable());

        $questions = array_values(array_filter(
            $survey->definition()->questions(),
            static fn (Question $question): bool => $questionIds === null || in_array($question->id, $questionIds, true),
        ));

        return $this->build($survey, $responses, $reason, $questions, forRespondents: false);
    }

    /**
     * What the people who answer may see: only when the survey shares its results, never hidden
     * questions or free text, and the names of voters only on named polls.
     */
    public function forRespondents(SurveyId $id, ?DateTimeImmutable $now = null): SurveyResultsDTO
    {
        $survey = $this->surveys->findOrFail($id);
        $now ??= now()->toDateTimeImmutable();
        $responses = $this->query->responseCount($id);

        $reason = match (true) {
            ! $survey->results()->visibleToRespondents => SurveyResultsDTO::NOT_SHARED,
            ! $survey->results()->liveResults && ! $survey->hasEnded($now) => SurveyResultsDTO::NOT_CLOSED,
            $survey->isAnonymous() && $responses < $this->settings->minResponses() => SurveyResultsDTO::BELOW_MINIMUM,
            default => null,
        };

        $questions = array_values(array_filter(
            $survey->definition()->questions(),
            static fn (Question $question): bool => ! $question->type->isFreeText() && ! $survey->results()->isHidden($question->id),
        ));

        return $this->build($survey, $responses, $reason, $questions, forRespondents: true);
    }

    /**
     * Why the panel (and the export) may not show an anonymous survey's results yet, or null.
     */
    public function panelHiddenReason(Survey $survey, int $responses, DateTimeImmutable $now): ?string
    {
        if (! $survey->isAnonymous()) {
            return null;
        }

        if (! $survey->results()->liveResults && ! $survey->hasEnded($now)) {
            return SurveyResultsDTO::NOT_CLOSED;
        }

        return $responses < $this->settings->minResponses() ? SurveyResultsDTO::BELOW_MINIMUM : null;
    }

    /**
     * @param  list<Question>  $questions
     */
    private function build(Survey $survey, int $responses, ?string $reason, array $questions, bool $forRespondents): SurveyResultsDTO
    {
        $audience = $survey->audienceSize();
        $rate = $audience > 0 ? round($responses / $audience, 4) : null;

        if ($reason !== null) {
            return new SurveyResultsDTO($responses, $audience, $rate, false, $reason);
        }

        $id = $survey->id();
        $ids = array_map(static fn (Question $question): string => $question->id, $questions);
        $answered = $this->query->answeredCounts($id, $ids);
        $optionIds = $this->idsOf($questions, static fn (QuestionType $type): bool => $type->hasOptions());
        $options = $this->query->optionCounts($id, $optionIds);
        $cells = $this->query->cellCounts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::Grid));
        $numbers = $this->query->numberCounts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::LinearScale || $type === QuestionType::StarRating));
        $dates = $this->query->textCounts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::Date || $type === QuestionType::Time));
        $texts = $forRespondents ? [] : $this->query->texts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type->isFreeText() || $type->allowsOther()));
        $voters = ! $survey->isAnonymous() && $survey->kind() === SurveyKind::Poll ? $this->query->voters($id, $optionIds) : [];

        // An optional question one person answered would give that answer away: the minimum
        // applies to each question of an anonymous survey too
        $minimum = $survey->isAnonymous() ? $this->settings->minResponses() : 0;

        $results = [];
        foreach ($questions as $question) {
            if (($answered[$question->id] ?? 0) < $minimum) {
                $results[] = new QuestionResultDTO(
                    questionId: $question->id,
                    title: $question->title,
                    type: $question->type,
                    chartType: $survey->results()->chartTypeFor($question),
                    hidden: $survey->results()->isHidden($question->id),
                    answered: $answered[$question->id] ?? 0,
                    belowMinimum: true,
                );

                continue;
            }

            $results[] = $this->question(
                $survey,
                $question,
                $answered[$question->id] ?? 0,
                $options[$question->id] ?? [],
                $cells[$question->id] ?? [],
                $numbers[$question->id] ?? [],
                $dates[$question->id] ?? [],
                $texts[$question->id] ?? [],
                $voters[$question->id] ?? [],
            );
        }

        return new SurveyResultsDTO($responses, $audience, $rate, true, null, $results);
    }

    /**
     * @param  array<string, int>  $options
     * @param  array<string, array<string, int>>  $cells
     * @param  array<int, int>  $numbers
     * @param  array<string, int>  $dates
     * @param  list<string>  $texts
     * @param  array<string, list<string>>  $voters
     */
    private function question(Survey $survey, Question $question, int $answered, array $options, array $cells, array $numbers, array $dates, array $texts, array $voters): QuestionResultDTO
    {
        $buckets = [];
        $grid = null;
        $mean = null;
        $median = null;

        if ($question->type->hasOptions()) {
            foreach ($question->options as $option) {
                $buckets[] = new ResultBucketDTO($option->id, $option->label, $options[$option->id] ?? 0, $voters[$option->id] ?? []);
            }

            if ($question->allowOther) {
                $buckets[] = new ResultBucketDTO(Submission::OTHER, null, $options[Submission::OTHER] ?? 0);
            }
        } elseif ($question->type === QuestionType::Grid && $question->grid instanceof GridSettings) {
            $counts = [];
            foreach ($question->grid->rows as $row) {
                foreach ($question->grid->columns as $column) {
                    $counts[$row->id][$column->id] = $cells[$row->id][$column->id] ?? 0;
                }
            }

            $grid = new GridResultDTO($question->grid->rows, $question->grid->columns, $counts);
        } elseif ($question->type === QuestionType::LinearScale || $question->type === QuestionType::StarRating) {
            [$min, $max] = $question->type === QuestionType::StarRating
                ? [1, $question->stars ?? 5]
                : [$question->scale->min ?? 1, $question->scale->max ?? 5];

            foreach (range($min, $max) as $value) {
                $buckets[] = new ResultBucketDTO((string) $value, (string) $value, $numbers[$value] ?? 0);
            }

            [$mean, $median] = $this->meanAndMedian($numbers);
        } elseif ($question->type === QuestionType::Date || $question->type === QuestionType::Time) {
            foreach ($this->histogram($question->type, $dates) as $key => $count) {
                $buckets[] = new ResultBucketDTO($key, $key, $count);
            }
        }

        return new QuestionResultDTO(
            questionId: $question->id,
            title: $question->title,
            type: $question->type,
            chartType: $survey->results()->chartTypeFor($question),
            hidden: $survey->results()->isHidden($question->id),
            answered: $answered,
            buckets: $buckets,
            grid: $grid,
            mean: $mean,
            median: $median,
            texts: $texts,
        );
    }

    /**
     * Dates by day; times by hour, so a handful of answers still draw a readable histogram.
     *
     * @param  array<string, int>  $values
     * @return array<string, int>
     */
    private function histogram(QuestionType $type, array $values): array
    {
        $histogram = [];
        foreach ($values as $value => $count) {
            $key = $type === QuestionType::Time ? substr($value, 0, 2).':00' : $value;
            $histogram[$key] = ($histogram[$key] ?? 0) + $count;
        }

        ksort($histogram, SORT_STRING);

        return $histogram;
    }

    /**
     * @param  array<int, int>  $counts  value => answers
     * @return array{?float, ?float}
     */
    private function meanAndMedian(array $counts): array
    {
        ksort($counts);
        $total = array_sum($counts);

        if ($total === 0) {
            return [null, null];
        }

        $sum = 0;
        foreach ($counts as $value => $count) {
            $sum += $value * $count;
        }

        return [round($sum / $total, 2), ($this->nth($counts, intdiv($total - 1, 2)) + $this->nth($counts, intdiv($total, 2))) / 2];
    }

    /**
     * The value at a 0-based position of the sorted answers.
     *
     * @param  array<int, int>  $counts  value => answers, sorted by value
     */
    private function nth(array $counts, int $position): float
    {
        $seen = 0;
        foreach ($counts as $value => $count) {
            $seen += $count;
            if ($position < $seen) {
                return (float) $value;
            }
        }

        return (float) array_key_last($counts);
    }

    /**
     * @param  list<Question>  $questions
     * @param  callable(QuestionType): bool  $filter
     * @return list<string>
     */
    private function idsOf(array $questions, callable $filter): array
    {
        $ids = [];
        foreach ($questions as $question) {
            if ($filter($question->type)) {
                $ids[] = $question->id;
            }
        }

        return $ids;
    }
}
