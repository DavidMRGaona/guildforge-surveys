<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Services;

use DateTimeImmutable;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;

/**
 * Checks a submission against its survey's definition. Returns the first problem of each
 * question (question id => translatable message key); no answer text ever leaves it. Only
 * the questions of the sections the answers lead to (SectionNavigator) are asked: those
 * of skipped sections are never required, and any answer to them is "not_reached".
 */
final class SubmissionValidator
{
    public const string OTHER = Submission::OTHER;

    private const int SHORT_TEXT_MAX = 500;

    private const int PARAGRAPH_MAX = 5000;

    private const string TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    /**
     * @return array<string, string>
     */
    public function validate(SurveyDefinition $definition, Submission $submission): array
    {
        $reached = (new SectionNavigator)->reachedQuestionIds($definition, $submission);

        $errors = [];
        foreach ($definition->questions() as $question) {
            $values = $submission->valuesFor($question->id);
            $error = in_array($question->id, $reached, true)
                ? $this->check($question, $values)
                : ($values === [] ? null : 'not_reached');
            if ($error !== null) {
                $errors[$question->id] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  list<AnswerValue>  $values
     */
    private function check(Question $question, array $values): ?string
    {
        if ($values === []) {
            return $question->required ? 'required' : null;
        }

        return match ($question->type) {
            QuestionType::ShortText => $this->checkText($values, self::SHORT_TEXT_MAX),
            QuestionType::Paragraph => $this->checkText($values, self::PARAGRAPH_MAX),
            QuestionType::SingleChoice, QuestionType::Dropdown => count($values) > 1 ? 'too_many_options' : $this->checkChoices($question, $values),
            QuestionType::MultipleChoice => $this->checkChoices($question, $values),
            QuestionType::LinearScale => $this->checkRange($values[0], $question->scale->min ?? 0, $question->scale->max ?? 0),
            QuestionType::StarRating => $this->checkRange($values[0], 1, $question->stars ?? 0),
            QuestionType::Date => $this->isDate($values[0]->text) ? null : 'invalid_date',
            QuestionType::Time => preg_match(self::TIME_PATTERN, $values[0]->text ?? '') === 1 ? null : 'invalid_time',
            QuestionType::Grid => $this->checkGrid($question, $values),
        };
    }

    /**
     * @param  list<AnswerValue>  $values
     */
    private function checkText(array $values, int $max): ?string
    {
        return mb_strlen($values[0]->text ?? '') > $max ? 'too_long' : null;
    }

    /**
     * @param  list<AnswerValue>  $values
     */
    private function checkChoices(Question $question, array $values): ?string
    {
        $optionIds = $this->ids($question->options);
        $seen = [];
        foreach ($values as $value) {
            // Each option counts once per response, "Other" included
            $choice = $value->isOther() ? Submission::OTHER : (string) $value->optionId;
            if (isset($seen[$choice])) {
                return 'duplicate_option';
            }
            $seen[$choice] = true;

            if ($value->isOther()) {
                if (! $question->allowOther) {
                    return 'invalid_option';
                }
                $text = trim($value->text ?? '');
                if ($text === '') {
                    return 'other_required';
                }
                if (mb_strlen($text) > self::SHORT_TEXT_MAX) {
                    return 'too_long';
                }
            } elseif (! in_array($value->optionId, $optionIds, true)) {
                return 'invalid_option';
            }
        }

        return null;
    }

    private function checkRange(AnswerValue $value, int $min, int $max): ?string
    {
        return $value->number === null || $value->number < $min || $value->number > $max ? 'out_of_range' : null;
    }

    /**
     * @param  list<AnswerValue>  $values
     */
    private function checkGrid(Question $question, array $values): ?string
    {
        $rowIds = $this->ids($question->grid->rows ?? []);
        $columnIds = $this->ids($question->grid->columns ?? []);
        $answeredRows = [];
        foreach ($values as $value) {
            if (! in_array($value->rowId, $rowIds, true) || ! in_array($value->optionId, $columnIds, true)) {
                return 'invalid_option';
            }
            if (isset($answeredRows[$value->rowId])) {
                return 'too_many_options';
            }
            $answeredRows[$value->rowId] = true;
        }

        return $question->required && count($answeredRows) < count($rowIds) ? 'grid_incomplete' : null;
    }

    private function isDate(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        return $date !== false && $date->format('Y-m-d') === $text;
    }

    /**
     * @param  list<Option>  $options
     * @return list<string>
     */
    private function ids(array $options): array
    {
        return array_map(static fn (Option $option): string => $option->id, $options);
    }
}
