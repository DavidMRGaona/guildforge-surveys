<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use DateTimeImmutable;
use Modules\Surveys\Application\Contracts\SurveyResultsQueryInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\Results\AvailabilityResultDTO;
use Modules\Surveys\Application\DTOs\Results\AvailabilitySlotResultDTO;
use Modules\Surveys\Application\DTOs\Results\GridResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\RankedOptionDTO;
use Modules\Surveys\Application\DTOs\Results\RankingResultDTO;
use Modules\Surveys\Application\DTOs\Results\ResultBucketDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AvailabilityAnswer;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\SectionNavigator;
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
        private AvailabilitySlotFormatter $slots,
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
        $reason = $this->panelHiddenReason($survey, $this->query->verifiedResponseCount($id), $now ?? now()->toDateTimeImmutable());

        $questions = array_values(array_filter(
            $survey->definition()->questions(),
            static fn (Question $question): bool => $questionIds === null || in_array($question->id, $questionIds, true),
        ));

        return $this->build($survey, $responses, $reason, $questions, forRespondents: false, withHidden: true);
    }

    /**
     * What the subject's organizer sees on the public site: the panel's rule, without the
     * questions the survey hides from its results.
     */
    public function forOrganizer(SurveyId $id, ?DateTimeImmutable $now = null): SurveyResultsDTO
    {
        $survey = $this->surveys->findOrFail($id);
        $responses = $this->query->responseCount($id);
        $reason = $this->panelHiddenReason($survey, $this->query->verifiedResponseCount($id), $now ?? now()->toDateTimeImmutable());

        $questions = array_values(array_filter(
            $survey->definition()->questions(),
            static fn (Question $question): bool => ! $survey->results()->isHidden($question->id),
        ));

        return $this->build($survey, $responses, $reason, $questions, forRespondents: false);
    }

    /**
     * What the people who answer may see: only when the survey shares its results, never hidden
     * questions or free text, and names only on named polls and availability surveys.
     */
    public function forRespondents(SurveyId $id, ?DateTimeImmutable $now = null): SurveyResultsDTO
    {
        $survey = $this->surveys->findOrFail($id);
        $now ??= now()->toDateTimeImmutable();
        $responses = $this->query->responseCount($id);

        $reason = match (true) {
            ! $survey->results()->visibleToRespondents => SurveyResultsDTO::NOT_SHARED,
            ! $survey->results()->liveResults && ! $survey->hasEnded($now) => SurveyResultsDTO::NOT_CLOSED,
            $survey->isAnonymous() && $this->query->verifiedResponseCount($id) < $this->settings->minResponses() => SurveyResultsDTO::BELOW_MINIMUM,
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
     * Only verified responses reach the minimum: anyone may send open link ones, and subtracting
     * them would give a lone verified response away.
     */
    public function panelHiddenReason(Survey $survey, int $verifiedResponses, DateTimeImmutable $now): ?string
    {
        if (! $survey->isAnonymous()) {
            return null;
        }

        if (! $survey->results()->liveResults && ! $survey->hasEnded($now)) {
            return SurveyResultsDTO::NOT_CLOSED;
        }

        return $verifiedResponses < $this->settings->minResponses() ? SurveyResultsDTO::BELOW_MINIMUM : null;
    }

    /**
     * @param  list<Question>  $questions
     */
    private function build(Survey $survey, int $responses, ?string $reason, array $questions, bool $forRespondents, bool $withHidden = false): SurveyResultsDTO
    {
        $audience = $survey->audienceSize();
        $rate = $audience > 0 ? round($responses / $audience, 4) : null;

        if ($reason !== null) {
            return new SurveyResultsDTO($responses, $audience, $rate, false, $reason);
        }

        $id = $survey->id();
        $ids = array_map(static fn (Question $question): string => $question->id, $questions);
        $answered = $this->query->answeredCounts($id, $ids);
        $optionIds = $this->idsOf($questions, static fn (QuestionType $type): bool => $type->isChoice());
        $options = $this->query->optionCounts($id, $optionIds);
        $cells = $this->query->cellCounts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::Grid || $type === QuestionType::Availability));
        $places = $this->query->positionCounts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::Ranking));
        $numbers = $this->query->numberCounts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::LinearScale || $type === QuestionType::StarRating));
        $dates = $this->query->textCounts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::Date || $type === QuestionType::Time));
        $texts = $forRespondents ? [] : $this->query->texts($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type->isFreeText() || $type->allowsOther()));
        $voters = ! $survey->isAnonymous() && $survey->kind() === SurveyKind::Poll ? $this->query->voters($id, $optionIds) : [];
        // Who can make each slot is the point of a named availability survey
        $cellVoters = $survey->isAnonymous() ? [] : $this->query->cellVoters($id, $this->idsOf($questions, static fn (QuestionType $type): bool => $type === QuestionType::Availability));

        // An optional question one person answered would give that answer away: the minimum
        // applies to each question of an anonymous survey too, counting verified answers only
        $minimum = $survey->isAnonymous() ? $this->settings->minResponses() : 0;
        $verified = $minimum > 0 ? $this->query->verifiedAnsweredCounts($id, $ids) : [];
        $reachedCounts = $this->jumpsShown($survey, $withHidden, $minimum) ? $this->reachedCounts($survey) : null;
        $reached = static function (string $questionId) use ($reachedCounts, $responses, $minimum): ?int {
            $counts = $reachedCounts[$questionId] ?? null;
            if ($counts === null || $counts['all'] >= $responses || $counts['verified'] < $minimum) {
                return null;
            }

            return $counts['all'];
        };

        $results = [];
        foreach ($questions as $question) {
            if ($minimum > 0 && ($verified[$question->id] ?? 0) < $minimum) {
                $results[] = new QuestionResultDTO(
                    questionId: $question->id,
                    title: $question->title,
                    type: $question->type,
                    chartType: $survey->results()->chartTypeFor($question),
                    hidden: $survey->results()->isHidden($question->id),
                    answered: $answered[$question->id] ?? 0,
                    belowMinimum: true,
                    reached: $reached($question->id),
                );

                continue;
            }

            $results[] = $this->question(
                $survey,
                $question,
                $answered[$question->id] ?? 0,
                $options[$question->id] ?? [],
                $cells[$question->id] ?? [],
                $places[$question->id] ?? [],
                $numbers[$question->id] ?? [],
                $dates[$question->id] ?? [],
                $texts[$question->id] ?? [],
                $voters[$question->id] ?? [],
                $cellVoters[$question->id] ?? [],
                $reached($question->id),
            );
        }

        return new SurveyResultsDTO($responses, $audience, $rate, true, null, $results);
    }

    /**
     * @param  array<string, int>  $options
     * @param  array<string, array<string, int>>  $cells
     * @param  array<string, array<int, int>>  $places  option id => place => answers
     * @param  array<int, int>  $numbers
     * @param  array<string, int>  $dates
     * @param  list<string>  $texts
     * @param  array<string, list<string>>  $voters
     * @param  array<string, array<string, list<string>>>  $cellVoters  row id => column id => names
     */
    private function question(Survey $survey, Question $question, int $answered, array $options, array $cells, array $places, array $numbers, array $dates, array $texts, array $voters, array $cellVoters, ?int $reached): QuestionResultDTO
    {
        $buckets = [];
        $grid = null;
        $ranking = null;
        $availability = null;
        $mean = null;
        $median = null;

        if ($question->type === QuestionType::Ranking) {
            $ranking = $this->ranking($question, $places);
        } elseif ($question->type === QuestionType::Availability) {
            $availability = $this->availability($question, $cells, $cellVoters, $survey->chosenSlot()?->id);
        } elseif ($question->type->isChoice()) {
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
            reached: $reached,
            ranking: $ranking,
            availability: $availability,
        );
    }

    /**
     * Each slot's answers in the survey's order, the best marked: most "yes", then most "if needed".
     *
     * @param  array<string, array<string, int>>  $cells  slot id => answer => count
     * @param  array<string, array<string, list<string>>>  $names  slot id => answer => names
     */
    private function availability(Question $question, array $cells, array $names, ?string $chosenSlotId): AvailabilityResultDTO
    {
        $yes = AvailabilityAnswer::Yes->value;
        $ifNeeded = AvailabilityAnswer::IfNeeded->value;
        $no = AvailabilityAnswer::No->value;

        $top = [0, 0];
        foreach ($question->slots as $slot) {
            $top = max($top, [$cells[$slot->id][$yes] ?? 0, $cells[$slot->id][$ifNeeded] ?? 0]);
        }

        $slots = [];
        foreach ($question->slots as $slot) {
            $counts = [$cells[$slot->id][$yes] ?? 0, $cells[$slot->id][$ifNeeded] ?? 0];
            $slots[] = new AvailabilitySlotResultDTO(
                slotId: $slot->id,
                label: $this->slots->label($slot),
                day: $this->slots->day($slot),
                start: $this->slots->start($slot),
                end: $this->slots->end($slot),
                yes: $counts[0],
                ifNeeded: $counts[1],
                no: $cells[$slot->id][$no] ?? 0,
                best: $top !== [0, 0] && $counts === $top,
                yesNames: $names[$slot->id][$yes] ?? [],
                ifNeededNames: $names[$slot->id][$ifNeeded] ?? [],
                noNames: $names[$slot->id][$no] ?? [],
                shortLabel: $this->slots->shortLabel($slot),
            );
        }

        return new AvailabilityResultDTO($slots, $chosenSlotId);
    }

    /**
     * Borda points (n for a first place out of n, n - 1 for a second…), the mean place and every
     * place's count of each option, best first. Every answer ranks every option, so each one has
     * as many places as there are answers.
     *
     * @param  array<string, array<int, int>>  $places  option id => place => answers
     */
    private function ranking(Question $question, array $places): RankingResultDTO
    {
        $positions = count($question->options);
        $ranked = [];
        foreach ($question->options as $index => $option) {
            $counts = [];
            $points = 0;
            $sum = 0;
            foreach (range(1, $positions) as $place) {
                $count = $places[$option->id][$place] ?? 0;
                $counts[$place] = $count;
                $points += ($positions - $place + 1) * $count;
                $sum += $place * $count;
            }
            $total = array_sum($counts);

            $ranked[] = ['index' => $index, 'option' => new RankedOptionDTO($option->id, $option->label, $points, $total > 0 ? round($sum / $total, 2) : null, $counts)];
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['option']->points, $a['option']->meanPosition ?? INF, $a['index']]
            <=> [$a['option']->points, $b['option']->meanPosition ?? INF, $b['index']]);

        return new RankingResultDTO($positions, array_column($ranked, 'option'));
    }

    /**
     * Whether this view shows every question that decides a jump: how many reached a question
     * tells how many chose the options leading there, so it is only given where those answers
     * are on show anyway (never hidden from the view, never below the minimum).
     */
    private function jumpsShown(Survey $survey, bool $withHidden, int $minimum): bool
    {
        $jumping = $survey->definition()->branchingQuestionIds();
        if (! $withHidden && array_any($jumping, static fn (string $id): bool => $survey->results()->isHidden($id))) {
            return false;
        }
        if ($minimum === 0 || $jumping === []) {
            return true;
        }

        $verified = $this->query->verifiedAnsweredCounts($survey->id(), $jumping);

        return array_all($jumping, static fn (string $id): bool => ($verified[$id] ?? 0) >= $minimum);
    }

    /**
     * How many responses (and verified ones) reached each question, walking each response's
     * answers to the jumping questions; null when the survey has no jumps and every response
     * reached everything. The definition never changes once there are responses, so the walk
     * today is the one each person took.
     *
     * @return array<string, array{all: int, verified: int}>|null
     */
    private function reachedCounts(Survey $survey): ?array
    {
        $definition = $survey->definition();
        if (! $definition->hasBranching()) {
            return null;
        }

        $navigator = new SectionNavigator;
        $counts = [];
        foreach ($this->query->responseValues($survey->id(), $definition->branchingQuestionIds()) as $response) {
            foreach ($navigator->reachedQuestionIds($definition, new Submission($response['values'])) as $questionId) {
                $counts[$questionId] ??= ['all' => 0, 'verified' => 0];
                $counts[$questionId]['all']++;
                $counts[$questionId]['verified'] += $response['verified'] ? 1 : 0;
            }
        }

        return $counts;
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
