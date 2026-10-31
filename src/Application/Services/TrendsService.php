<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Services\TimezoneServiceInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\Contracts\TrendsQueryInterface;
use Modules\Surveys\Application\DTOs\Trends\QuestionTrendDTO;
use Modules\Surveys\Application\DTOs\Trends\TemplateTrendsDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendComparison;
use Modules\Surveys\Application\DTOs\Trends\TrendFilterOptionsDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\DTOs\Trends\TrendPointDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendSeriesDTO;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\GridSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

/**
 * How a template's keyed questions evolve across the surveys made from it.
 *
 * Only surveys whose results could already be seen on their own count (closed, or with live
 * results, and with the minimum of responses; on anonymous ones, each question with its
 * minimum too), so no combination of filters gives away a survey that is hidden by itself.
 * On top of that, every cut (a series in a period) needs the minimum of answers.
 *
 * @phpstan-type Item array{key: string, questionKey: string, title: string, question: Question, rowId: ?string, measure: string, min: ?int, max: ?int, options: list<array{id: string, label: string}>}
 * @phpstan-type Cell array{answered: int, verified: int, sum: int, values: int, denominator: int, counts: array<string, int>}
 */
final readonly class TrendsService
{
    public const string GAME_SYSTEM = 'game_system';

    public const int MAX_SERIES = 8;

    private const string ALL = '*all';

    private const string REST = '*rest';

    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyTemplateRepositoryInterface $templates,
        private TrendsQueryInterface $query,
        private ResultsService $results,
        private SurveySettingsInterface $settings,
        private TimezoneServiceInterface $timezone,
        private SurveySubjectSourceRegistryInterface $sources,
    ) {}

    /**
     * What the organization sees in the panel: every survey of the template.
     *
     * @param  string|null  $onlyKey  only this question (a chart widget needs one)
     */
    public function forPanel(SurveyTemplateId $templateId, TrendFilters $filters, ?string $onlyKey = null, ?DateTimeImmutable $now = null): TemplateTrendsDTO
    {
        return $this->build($templateId, $filters, null, $onlyKey, $now ?? now()->toDateTimeImmutable());
    }

    /**
     * What an organizer sees on the site: the surveys whose snapshot names them, without the
     * questions each survey hides from its results, and nothing about other organizers.
     */
    public function forOrganizer(SurveyTemplateId $templateId, string $userId, TrendFilters $filters, ?string $onlyKey = null, ?DateTimeImmutable $now = null): TemplateTrendsDTO
    {
        return $this->build($templateId, $filters->withoutOrganizer(), $userId, $onlyKey, $now ?? now()->toDateTimeImmutable());
    }

    /**
     * The choices of the filters, from every survey of the template (or of this organizer).
     */
    public function filterOptions(SurveyTemplateId $templateId, ?string $organizerId = null): TrendFilterOptionsDTO
    {
        $types = [];
        $organizers = [];
        $systems = [];

        foreach ($this->candidates($templateId, $organizerId) as $survey) {
            $subject = $survey->subject();
            if (! $subject instanceof SubjectSnapshot) {
                continue;
            }

            $types[$subject->sourceType] = true;
            foreach ($subject->organizerIds as $userId) {
                $organizers[$userId] = true;
            }

            $system = $this->gameSystem($subject);
            if ($system !== null) {
                $systems[$system] = true;
            }
        }

        $typeOptions = array_map(fn (string $type): array => ['value' => $type, 'label' => $this->typeLabel($type)], $this->keys($types));
        $systemNames = $this->keys($systems);
        sort($systemNames, SORT_NATURAL | SORT_FLAG_CASE);

        return new TrendFilterOptionsDTO(
            subjectTypes: $this->sortedByLabel($typeOptions),
            organizers: $organizerId === null ? $this->organizerOptions($this->keys($organizers)) : [],
            gameSystems: $systemNames,
        );
    }

    /**
     * Templates with surveys that have opened, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function templatesFor(?string $organizerId = null): array
    {
        $templates = [];
        foreach ($this->query->templateIds($organizerId) as $id) {
            $template = $this->templates->find(SurveyTemplateId::fromString($id));
            if ($template instanceof SurveyTemplate) {
                $templates[] = ['id' => $template->id()->value, 'name' => $template->name()];
            }
        }

        usort($templates, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

        return $templates;
    }

    private function build(SurveyTemplateId $templateId, TrendFilters $filters, ?string $organizerId, ?string $onlyKey, DateTimeImmutable $now): TemplateTrendsDTO
    {
        $template = $this->templates->findOrFail($templateId);
        $minimum = $this->settings->minResponses();
        $candidates = $this->candidates($templateId, $organizerId);
        $responses = $this->query->responseCounts(array_map(static fn (Survey $survey): string => $survey->id()->value, $candidates));

        /** @var array<string, Survey> $surveys */
        $surveys = [];
        /** @var array<string, string> $periodOf */
        $periodOf = [];
        foreach ($candidates as $survey) {
            $id = $survey->id()->value;
            $count = $responses[$id] ?? 0;
            $date = $this->dateOf($survey);

            if ($count === 0 || ! $date instanceof DateTimeImmutable || $this->results->panelHiddenReason($survey, $count, $now) !== null || ! $this->matches($survey, $date, $filters)) {
                continue;
            }

            $surveys[$id] = $survey;
            $periodOf[$id] = $filters->period->keyFor($date);
        }

        $total = array_sum(array_intersect_key($responses, $surveys));
        if ($surveys === [] || $total < $minimum) {
            return new TemplateTrendsDTO($templateId->value, $template->name(), count($surveys), $total, false);
        }

        [$seriesOf, $labels] = $this->series($surveys, $responses, $filters->compareBy);
        $periodKeys = array_values(array_unique($periodOf));
        sort($periodKeys, SORT_STRING);
        $periods = array_map(
            static fn (string $key): array => ['key' => $key, 'label' => $filters->period->label($key)],
            $filters->period->between($periodKeys[0], $periodKeys[count($periodKeys) - 1]),
        );

        $items = $this->items($template, $onlyKey);
        $resolved = $this->resolve($items, $surveys, $organizerId !== null);
        $questionIds = static fn (callable $filter): array => array_values(array_unique(array_merge([], ...array_map(
            static fn (array $bySurvey, int $index): array => $filter($items[$index]) ? array_values($bySurvey) : [],
            $resolved,
            array_keys($resolved),
        ))));

        $ids = array_keys($surveys);
        $answered = $this->query->answeredCounts($ids, $questionIds(static fn (array $item): bool => true));
        $numbers = $this->query->numberCounts($ids, $questionIds(static fn (array $item): bool => $item['measure'] === QuestionTrendDTO::MEAN));
        $options = $this->query->optionCounts($ids, $questionIds(static fn (array $item): bool => $item['measure'] === QuestionTrendDTO::SHARES && $item['rowId'] === null));
        $cells = $this->query->cellCounts($ids, $questionIds(static fn (array $item): bool => $item['rowId'] !== null));

        $questions = [];
        foreach ($items as $index => $item) {
            if ($item['measure'] === QuestionTrendDTO::NONE) {
                $questions[] = new QuestionTrendDTO($item['key'], $item['title'], $item['question']->type, QuestionTrendDTO::NONE);

                continue;
            }

            $byTotal = $item['measure'] === QuestionTrendDTO::SHARES && $filters->compareBy !== TrendComparison::None;

            /** @var array<string, array<string, Cell>> $grid series => period => cell */
            $grid = [];
            foreach ($resolved[$index] ?? [] as $surveyId => $questionId) {
                $counts = $answered[$surveyId][$questionId] ?? ['all' => 0, 'verified' => 0];
                // On anonymous surveys a question below the minimum stays hidden, here as in its results
                if ($counts['all'] === 0 || ($surveys[$surveyId]->isAnonymous() && $counts['verified'] < $minimum)) {
                    continue;
                }

                $period = $byTotal ? QuestionTrendDTO::TOTAL : $periodOf[$surveyId];
                foreach ($seriesOf[$surveyId] as $series) {
                    $grid[$series][$period] = $this->add(
                        $grid[$series][$period] ?? ['answered' => 0, 'verified' => 0, 'sum' => 0, 'values' => 0, 'denominator' => 0, 'counts' => []],
                        $item,
                        $counts,
                        $numbers[$surveyId][$questionId] ?? [],
                        $options[$surveyId][$questionId] ?? [],
                        $item['rowId'] === null ? [] : ($cells[$surveyId][$questionId][$item['rowId']] ?? []),
                    );
                }
            }

            $questions[] = $this->question($item, $byTotal ? [['key' => QuestionTrendDTO::TOTAL, 'label' => (string) __('surveys::messages.trends.total')]] : $periods, $grid, $labels, $minimum);
        }

        return new TemplateTrendsDTO($templateId->value, $template->name(), count($surveys), $total, true, $questions);
    }

    /**
     * The surveys of the template that have opened, or those whose snapshot names this organizer.
     *
     * @return list<Survey>
     */
    private function candidates(SurveyTemplateId $templateId, ?string $organizerId): array
    {
        $surveys = $this->surveys->findByTemplate($templateId);

        if ($organizerId === null) {
            return $surveys;
        }

        return array_values(array_filter(
            $surveys,
            static fn (Survey $survey): bool => in_array($organizerId, $survey->subject()->organizerIds ?? [], true),
        ));
    }

    /**
     * When the subject took place, in the display time zone: its start, else its end; a survey
     * without subject, when it opened.
     */
    private function dateOf(Survey $survey): ?DateTimeImmutable
    {
        $date = $survey->subject()->startsAt ?? $survey->subject()->endsAt ?? $survey->opensAt();

        return $date instanceof DateTimeImmutable ? $this->timezone->toDisplay($date) : null;
    }

    private function matches(Survey $survey, DateTimeImmutable $date, TrendFilters $filters): bool
    {
        $subject = $survey->subject();
        $day = $date->format('Y-m-d');

        return ($filters->subjectType === null || $subject?->sourceType === $filters->subjectType)
            && ($filters->organizerId === null || in_array($filters->organizerId, $subject->organizerIds ?? [], true))
            && ($filters->gameSystem === null || ($subject instanceof SubjectSnapshot && $this->gameSystem($subject) === $filters->gameSystem))
            && (! $filters->from instanceof DateTimeImmutable || $day >= $filters->from->format('Y-m-d'))
            && (! $filters->to instanceof DateTimeImmutable || $day <= $filters->to->format('Y-m-d'));
    }

    private function gameSystem(SubjectSnapshot $subject): ?string
    {
        foreach ($subject->attributes as $attribute) {
            if ($attribute['key'] === self::GAME_SYSTEM && (is_string($attribute['value']) || is_int($attribute['value']) || is_float($attribute['value'])) && trim((string) $attribute['value']) !== '') {
                return (string) $attribute['value'];
            }
        }

        return null;
    }

    /**
     * Which series each survey counts in, and the series' labels, most answered first. Past
     * MAX_SERIES, the last one gathers the rest.
     *
     * @param  array<string, Survey>  $surveys
     * @param  array<string, int>  $responses
     * @return array{array<string, list<string>>, array<string, array{id: ?string, label: string}>}
     */
    private function series(array $surveys, array $responses, TrendComparison $compareBy): array
    {
        $seriesOf = [];
        $weights = [];
        foreach ($surveys as $id => $survey) {
            $subject = $survey->subject();
            $keys = match ($compareBy) {
                TrendComparison::None => [self::ALL],
                TrendComparison::Organizer => ($subject->organizerIds ?? []) === [] ? [''] : array_values(array_unique($subject->organizerIds ?? [])),
                TrendComparison::GameSystem => [$subject instanceof SubjectSnapshot ? ($this->gameSystem($subject) ?? '') : ''],
                TrendComparison::SubjectType => [$subject->sourceType ?? ''],
            };

            $seriesOf[$id] = $keys;
            foreach ($keys as $key) {
                $weights[$key] = ($weights[$key] ?? 0) + ($responses[$id] ?? 0);
            }
        }

        $names = $compareBy === TrendComparison::Organizer
            ? $this->query->userNames(array_values(array_filter($this->keys($weights), static fn (string $key): bool => $key !== '')))
            : [];

        $labels = [];
        foreach ($this->keys($weights) as $key) {
            $labels[$key] = ['id' => $key === self::ALL ? null : $key, 'label' => $this->seriesLabel($compareBy, $key, $names)];
        }

        $order = $this->keys($weights);
        usort($order, static fn (string $a, string $b): int => [$weights[$b], $labels[$a]['label']] <=> [$weights[$a], $labels[$b]['label']]);

        if (count($order) > self::MAX_SERIES) {
            $kept = array_slice($order, 0, self::MAX_SERIES - 1);
            foreach ($seriesOf as $id => $keys) {
                $seriesOf[$id] = array_values(array_unique(array_map(static fn (string $key): string => in_array($key, $kept, true) ? $key : self::REST, $keys)));
            }
            $order = [...$kept, self::REST];
            $labels[self::REST] = ['id' => null, 'label' => (string) __('surveys::messages.trends.rest')];
        }

        $sorted = [];
        foreach ($order as $key) {
            $sorted[$key] = $labels[$key];
        }

        return [$seriesOf, $sorted];
    }

    /**
     * @param  array<string, string>  $names
     */
    private function seriesLabel(TrendComparison $compareBy, string $key, array $names): string
    {
        return match ($compareBy) {
            TrendComparison::None => (string) __('surveys::messages.trends.all'),
            TrendComparison::Organizer => $key === '' ? (string) __('surveys::messages.trends.no_organizer') : ($names[$key] ?? (string) __('surveys::messages.trends.unknown_organizer')),
            TrendComparison::GameSystem => $key === '' ? (string) __('surveys::messages.trends.no_game_system') : $key,
            TrendComparison::SubjectType => $this->typeLabel($key),
        };
    }

    private function typeLabel(string $type): string
    {
        if ($type === '') {
            return (string) __('surveys::messages.trends.general');
        }

        $source = $this->sources->source($type);

        return $source instanceof SurveySubjectSourceInterface ? $source->sourceLabel() : $type;
    }

    /**
     * @param  list<string>  $userIds
     * @return list<array{value: string, label: string}>
     */
    private function organizerOptions(array $userIds): array
    {
        $names = $this->query->userNames($userIds);

        return $this->sortedByLabel(array_map(
            static fn (string $userId): array => ['value' => $userId, 'label' => $names[$userId] ?? (string) __('surveys::messages.trends.unknown_organizer')],
            $userIds,
        ));
    }

    /**
     * What trends the template has: one item per keyed question, one per row of a keyed grid.
     *
     * @return list<Item>
     */
    private function items(SurveyTemplate $template, ?string $onlyKey): array
    {
        $items = [];
        foreach ($template->definition()->questions() as $question) {
            if ($question->key === null) {
                continue;
            }

            $base = ['key' => $question->key, 'questionKey' => $question->key, 'title' => $question->title, 'question' => $question, 'rowId' => null, 'min' => null, 'max' => null, 'options' => []];

            $found = match ($question->type) {
                QuestionType::LinearScale => [[...$base, 'measure' => QuestionTrendDTO::MEAN, 'min' => $question->scale->min ?? 1, 'max' => $question->scale->max ?? 5]],
                QuestionType::StarRating => [[...$base, 'measure' => QuestionTrendDTO::MEAN, 'min' => 1, 'max' => $question->stars ?? 5]],
                QuestionType::SingleChoice, QuestionType::Dropdown, QuestionType::MultipleChoice => [[...$base, 'measure' => QuestionTrendDTO::SHARES, 'options' => $this->choiceOptions($question)]],
                QuestionType::Grid => array_map(static fn ($row): array => [
                    ...$base,
                    'key' => "{$question->key}.{$row->id}",
                    'title' => (string) __('surveys::messages.trends.row_title', ['question' => $question->title, 'row' => $row->label]),
                    'rowId' => $row->id,
                    'measure' => QuestionTrendDTO::SHARES,
                    'options' => array_map(static fn ($column): array => ['id' => $column->id, 'label' => $column->label], $question->grid->columns ?? []),
                ], $question->grid->rows ?? []),
                default => [[...$base, 'measure' => QuestionTrendDTO::NONE]],
            };

            foreach ($found as $item) {
                if ($onlyKey === null || $item['key'] === $onlyKey) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function choiceOptions(Question $question): array
    {
        $options = array_map(static fn ($option): array => ['id' => $option->id, 'label' => $option->label], $question->options);

        if ($question->allowOther) {
            $options[] = ['id' => Submission::OTHER, 'label' => (string) __('surveys::messages.trends.other')];
        }

        return $options;
    }

    /**
     * The question each survey asked for each item: same key and type as the template's, the
     * same scale, and the grid row still there. Organizers lose what a survey hides from them.
     *
     * @param  list<Item>  $items
     * @param  array<string, Survey>  $surveys
     * @return array<int, array<string, string>> item index => survey id => question id
     */
    private function resolve(array $items, array $surveys, bool $forOrganizer): array
    {
        $resolved = [];
        foreach ($surveys as $surveyId => $survey) {
            $byKey = [];
            foreach ($survey->definition()->questions() as $question) {
                if ($question->key !== null) {
                    $byKey[$question->key] = $question;
                }
            }

            foreach ($items as $index => $item) {
                $asked = $byKey[$item['questionKey']] ?? null;
                if ($item['measure'] === QuestionTrendDTO::NONE || ! $asked instanceof Question || ! $this->sameQuestion($item, $asked)) {
                    continue;
                }

                if ($forOrganizer && $survey->results()->isHidden($asked->id)) {
                    continue;
                }

                $resolved[$index][$surveyId] = $asked->id;
            }
        }

        return $resolved;
    }

    /**
     * @param  Item  $item
     */
    private function sameQuestion(array $item, Question $asked): bool
    {
        $template = $item['question'];

        return $asked->type === $template->type && match ($template->type) {
            QuestionType::LinearScale => [$asked->scale->min ?? 1, $asked->scale->max ?? 5] === [$item['min'], $item['max']],
            QuestionType::StarRating => ($asked->stars ?? 5) === $item['max'],
            QuestionType::Grid => $asked->grid instanceof GridSettings && in_array($item['rowId'], array_map(static fn ($row): string => $row->id, $asked->grid->rows), true),
            default => true,
        };
    }

    /**
     * Adds one survey's answers to a cut.
     *
     * @param  Cell  $cell
     * @param  Item  $item
     * @param  array{all: int, verified: int}  $counts
     * @param  array<int, int>  $numbers
     * @param  array<string, int>  $options
     * @param  array<string, int>  $row  column id => answers, for a grid row
     * @return Cell
     */
    private function add(array $cell, array $item, array $counts, array $numbers, array $options, array $row): array
    {
        $cell['verified'] += $counts['verified'];
        $known = array_column($item['options'], 'id');

        if ($item['measure'] === QuestionTrendDTO::MEAN) {
            $cell['answered'] += $counts['all'];
            foreach ($numbers as $value => $count) {
                $cell['sum'] += $value * $count;
                $cell['values'] += $count;
            }

            return $cell;
        }

        if ($item['rowId'] !== null) {
            // A grid row counts the people who answered that row
            $cell['answered'] += array_sum($row);
            $cell['denominator'] += array_sum($row);
            $answers = $row;
        } else {
            // The share of the people who answered: checkboxes add up to more than one
            $cell['answered'] += $counts['all'];
            $cell['denominator'] += $counts['all'];
            $answers = $options;
        }

        foreach ($answers as $option => $count) {
            $option = (string) $option;
            $bucket = in_array($option, $known, true) ? $option : QuestionTrendDTO::PREVIOUS;
            $cell['counts'][$bucket] = ($cell['counts'][$bucket] ?? 0) + $count;
        }

        return $cell;
    }

    /**
     * @param  Item  $item
     * @param  list<array{key: string, label: string}>  $periods
     * @param  array<string, array<string, Cell>>  $grid
     * @param  array<string, array{id: ?string, label: string}>  $labels  every series, in order
     */
    private function question(array $item, array $periods, array $grid, array $labels, int $minimum): QuestionTrendDTO
    {
        $options = $item['options'];
        foreach ($grid as $byPeriod) {
            foreach ($byPeriod as $cell) {
                if (($cell['counts'][QuestionTrendDTO::PREVIOUS] ?? 0) > 0) {
                    $options[] = ['id' => QuestionTrendDTO::PREVIOUS, 'label' => (string) __('surveys::messages.trends.previous')];
                    break 2;
                }
            }
        }

        $series = [];
        foreach ($labels as $key => $label) {
            if (! isset($grid[$key])) {
                continue;
            }

            $points = [];
            foreach ($periods as $period) {
                $points[] = $this->point($period['key'], $grid[$key][$period['key']] ?? null, $item['measure'], $options, $minimum);
            }

            $series[] = new TrendSeriesDTO($label['id'], $label['label'], $points);
        }

        return new QuestionTrendDTO($item['key'], $item['title'], $item['question']->type, $item['measure'], $item['min'], $item['max'], $periods, $options, $series);
    }

    /**
     * @param  Cell|null  $cell
     * @param  list<array{id: string, label: string}>  $options
     */
    private function point(string $period, ?array $cell, string $measure, array $options, int $minimum): TrendPointDTO
    {
        if ($cell === null || $cell['answered'] === 0) {
            return new TrendPointDTO($period, 0, false);
        }

        if ($cell['verified'] < $minimum) {
            return new TrendPointDTO($period, $cell['answered'], true);
        }

        if ($measure === QuestionTrendDTO::MEAN) {
            return new TrendPointDTO($period, $cell['answered'], false, mean: $cell['values'] > 0 ? round($cell['sum'] / $cell['values'], 2) : null);
        }

        $shares = [];
        foreach ($options as $option) {
            $shares[$option['id']] = $cell['denominator'] > 0 ? ($cell['counts'][$option['id']] ?? 0) / $cell['denominator'] : 0.0;
        }

        return new TrendPointDTO($period, $cell['answered'], false, shares: $shares);
    }

    /**
     * Array keys as strings: PHP turns numeric ones (a system called «1984») into integers.
     *
     * @param  array<array-key, mixed>  $array
     * @return list<string>
     */
    private function keys(array $array): array
    {
        return array_map(static fn (int|string $key): string => (string) $key, array_keys($array));
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return list<array{value: string, label: string}>
     */
    private function sortedByLabel(array $options): array
    {
        usort($options, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $options;
    }
}
