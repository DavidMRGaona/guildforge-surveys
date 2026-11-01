<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Submission;

use Modules\Surveys\Domain\Enums\AvailabilityAnswer;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;

/**
 * The values someone sent for a survey, normalised against its definition. Input that
 * does not fit a question's shape is kept as far as possible so SubmissionValidator can
 * say what is wrong; answers to questions the survey does not have are dropped.
 */
final readonly class Submission
{
    /** Option id that stands for "Other" in the input. */
    public const string OTHER = '__other__';

    private const string TIME_WITH_SECONDS = '/^(\d{2}:\d{2}):\d{2}$/';

    /**
     * @param  list<AnswerValue>  $values
     */
    public function __construct(public array $values) {}

    /**
     * Input is keyed by question id: a string for text, date, time and a single option;
     * an int or numeric string for scales and stars; a list of option ids for checkboxes;
     * {option, other} or {options, other} when "Other" is chosen; {rowId: columnId} for grids;
     * the option ids in order, best first, for a ranking; {slotId: answer} for availability,
     * where every slot left out counts as "no" (sending the form is the answer).
     *
     * @param  array<mixed>  $input
     */
    public static function fromInput(SurveyDefinition $definition, array $input): self
    {
        $values = [];
        foreach ($definition->questions() as $question) {
            if ($question->type === QuestionType::Availability) {
                array_push($values, ...self::availability($question, $input[$question->id] ?? []));
            } elseif (array_key_exists($question->id, $input)) {
                array_push($values, ...self::normalise($question, $input[$question->id]));
            }
        }

        return new self($values);
    }

    /**
     * @return list<AnswerValue>
     */
    public function valuesFor(string $questionId): array
    {
        return array_values(array_filter($this->values, static fn (AnswerValue $value): bool => $value->questionId === $questionId));
    }

    /**
     * @return list<AnswerValue>
     */
    private static function normalise(Question $question, mixed $raw): array
    {
        $id = $question->id;

        return match (true) {
            $question->type === QuestionType::Ranking => self::places($id, $raw),
            $question->type->isChoice() => self::choices($id, $raw),
            $question->type === QuestionType::Grid => self::gridCells($id, $raw),
            in_array($question->type, [QuestionType::LinearScale, QuestionType::StarRating], true) => self::number($id, $raw),
            default => self::text($id, $raw, $question->type),
        };
    }

    /**
     * @return list<AnswerValue>
     */
    private static function choices(string $questionId, mixed $raw): array
    {
        $other = null;
        if (is_array($raw) && ! array_is_list($raw)) {
            $other = is_string($raw['other'] ?? null) ? trim($raw['other']) : '';
            $raw = $raw['options'] ?? $raw['option'] ?? [];
        }

        $values = [];
        foreach (is_array($raw) ? $raw : [$raw] as $optionId) {
            if (! is_string($optionId) || $optionId === '') {
                continue;
            }
            $values[] = $optionId === self::OTHER
                ? new AnswerValue($questionId, text: $other ?? '')
                : new AnswerValue($questionId, optionId: $optionId);
        }

        return $values;
    }

    /**
     * One value per ranked option, its place (1 = first) as the number. A lone id is kept as a
     * ranking of one, so SubmissionValidator says it is incomplete.
     *
     * @return list<AnswerValue>
     */
    private static function places(string $questionId, mixed $raw): array
    {
        $values = [];
        foreach (is_array($raw) ? array_values($raw) : [$raw] as $optionId) {
            if (is_string($optionId) && $optionId !== '') {
                $values[] = new AnswerValue($questionId, optionId: $optionId, number: count($values) + 1);
            }
        }

        return $values;
    }

    /**
     * @return list<AnswerValue>
     */
    private static function gridCells(string $questionId, mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $values = [];
        foreach ($raw as $rowId => $columnId) {
            if (is_string($columnId) && $columnId !== '') {
                $values[] = new AnswerValue($questionId, optionId: $columnId, rowId: (string) $rowId);
            }
        }

        return $values;
    }

    /**
     * One value per slot, as a grid cell (the slot as row, the answer as column), "no" for
     * every slot the input leaves out. Unknown slots and answers are kept for SubmissionValidator.
     *
     * @return list<AnswerValue>
     */
    private static function availability(Question $question, mixed $raw): array
    {
        $values = self::gridCells($question->id, $raw);
        $answered = array_map(static fn (AnswerValue $value): ?string => $value->rowId, $values);
        foreach ($question->slots as $slot) {
            if (! in_array($slot->id, $answered, true)) {
                $values[] = new AnswerValue($question->id, optionId: AvailabilityAnswer::No->value, rowId: $slot->id);
            }
        }

        return $values;
    }

    /**
     * @return list<AnswerValue>
     */
    private static function number(string $questionId, mixed $raw): array
    {
        if (is_int($raw)) {
            return [new AnswerValue($questionId, number: $raw)];
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $number = filter_var(trim($raw), FILTER_VALIDATE_INT);

        // A value that is not a whole number stays as text, without number, and fails the range check
        return [$number === false ? new AnswerValue($questionId, text: $raw) : new AnswerValue($questionId, number: $number)];
    }

    /**
     * @return list<AnswerValue>
     */
    private static function text(string $questionId, mixed $raw, QuestionType $type): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $text = trim($raw);
        if ($type === QuestionType::Time && preg_match(self::TIME_WITH_SECONDS, $text, $matches) === 1) {
            $text = $matches[1];
        }

        return [new AnswerValue($questionId, text: $text)];
    }
}
