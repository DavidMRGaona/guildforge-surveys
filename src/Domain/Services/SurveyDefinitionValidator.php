<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Services;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\GridSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\ScaleSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;

/**
 * The single set of rules for survey content. Every editor (panel and public site) goes
 * through it, so a definition stored by one is always valid for the other.
 */
final class SurveyDefinitionValidator
{
    private const string KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const array SCALE_MINIMUMS = [0, 1];

    private const int SCALE_MAX_FROM = 2;

    private const int SCALE_MAX_TO = 10;

    private const int STARS_FROM = 3;

    private const int STARS_TO = 10;

    /** More options than this are hard to put in order, above all on a phone. */
    public const int RANKING_OPTIONS_MAX = 10;

    public const int SLOTS_MIN = 2;

    /** Enough for every evening of a month; more is no longer a choice people can weigh. */
    public const int SLOTS_MAX = 30;

    /**
     * @return list<DefinitionViolation>
     */
    public function validate(SurveyDefinition $definition, SurveyKind $kind): array
    {
        $violations = [];
        $sectionIds = [];
        $questionIds = [];
        $keys = [];

        if ($definition->questions() === []) {
            $violations[] = new DefinitionViolation('sections', 'no_questions');
        }

        foreach ($definition->sections as $s => $section) {
            $this->checkId($section->id, "sections.{$s}.id", $sectionIds, $violations);
            if ($section->id === Section::SUBMIT) {
                $violations[] = new DefinitionViolation("sections.{$s}.id", 'reserved_id');
            }
            if ($section->next !== null) {
                array_push($violations, ...$this->checkTarget($definition, $s, $section->next, "sections.{$s}.next"));
            }

            $branching = 0;
            foreach ($section->questions as $q => $question) {
                $path = "sections.{$s}.questions.{$q}";
                $this->checkId($question->id, "{$path}.id", $questionIds, $violations);

                if ($question->nextByOption !== []) {
                    array_push($violations, ...$this->checkJumps($definition, $s, $question, "{$path}.next_by_option", ++$branching));
                }

                if (trim($question->title) === '') {
                    $violations[] = new DefinitionViolation("{$path}.title", 'required_title');
                }

                if ($question->key !== null) {
                    if (preg_match(self::KEY_PATTERN, $question->key) !== 1) {
                        $violations[] = new DefinitionViolation("{$path}.key", 'invalid_key');
                    } elseif (isset($keys[$question->key])) {
                        $violations[] = new DefinitionViolation("{$path}.key", 'duplicate_key');
                    }
                    $keys[$question->key] = true;
                }

                array_push($violations, ...$this->checkTypeSettings($question, $path));
                if ($question->type === QuestionType::Availability && $kind === SurveyKind::Form) {
                    $violations[] = new DefinitionViolation("{$path}.type", 'availability_kind_only');
                }
            }
        }

        if ($kind === SurveyKind::Poll) {
            array_push($violations, ...$this->checkPoll($definition));
        }

        if ($kind === SurveyKind::Availability) {
            array_push($violations, ...$this->checkAvailability($definition));
        }

        return $violations;
    }

    /**
     * Rules for replacing a stored definition: a question with a key never changes type
     * (it would break its series over time), and once there are responses only the help
     * texts may change, since any other edit would change what earlier answers mean. With
     * $keysFixed (a survey, not a template) no key may appear that the old content did not have:
     * keys only come from templates, so nobody can slip a question into a template's series.
     *
     * @return list<DefinitionViolation>
     */
    public function validateChange(SurveyDefinition $old, SurveyDefinition $new, bool $hasResponses, bool $keysFixed = false): array
    {
        $violations = [];

        $oldTypesByKey = [];
        foreach ($old->questions() as $question) {
            if ($question->key !== null) {
                $oldTypesByKey[$question->key] = $question->type;
            }
        }

        foreach ($new->sections as $s => $section) {
            foreach ($section->questions as $q => $question) {
                $oldType = $question->key === null ? null : ($oldTypesByKey[$question->key] ?? null);
                if ($oldType !== null && $oldType !== $question->type) {
                    $violations[] = new DefinitionViolation("sections.{$s}.questions.{$q}.type", 'type_locked');
                }
                if ($keysFixed && $question->key !== null && ! array_key_exists($question->key, $oldTypesByKey)) {
                    $violations[] = new DefinitionViolation("sections.{$s}.questions.{$q}.key", 'key_not_allowed');
                }
            }
        }

        if ($hasResponses && $this->withoutHelpTexts($old) !== $this->withoutHelpTexts($new)) {
            $violations[] = new DefinitionViolation('sections', 'locked_by_responses');
        }

        return $violations;
    }

    /**
     * @throws InvalidSurveyDefinitionException
     */
    public function assertValid(SurveyDefinition $definition, SurveyKind $kind): void
    {
        $this->throwIfAny($this->validate($definition, $kind));
    }

    /**
     * @throws InvalidSurveyDefinitionException
     */
    public function assertChangeAllowed(SurveyDefinition $old, SurveyDefinition $new, bool $hasResponses, bool $keysFixed = false): void
    {
        $this->throwIfAny($this->validateChange($old, $new, $hasResponses, $keysFixed));
    }

    /**
     * @return list<DefinitionViolation>
     */
    private function checkTypeSettings(Question $question, string $path): array
    {
        $violations = [];
        $type = $question->type;

        if ($type->hasOptions()) {
            if (count($question->options) < 2) {
                $violations[] = new DefinitionViolation("{$path}.options", 'too_few_options');
            }
            if ($type === QuestionType::Ranking && count($question->options) > self::RANKING_OPTIONS_MAX) {
                $violations[] = new DefinitionViolation("{$path}.options", 'too_many_ranking_options');
            }
            array_push($violations, ...$this->checkOptions($question->options, "{$path}.options"));
        } elseif ($question->options !== []) {
            $violations[] = new DefinitionViolation("{$path}.options", 'options_not_allowed');
        }

        if ($question->allowOther && ! $type->allowsOther()) {
            $violations[] = new DefinitionViolation("{$path}.allow_other", 'other_not_allowed');
        }

        if ($type === QuestionType::LinearScale) {
            if (! $question->scale instanceof ScaleSettings) {
                $violations[] = new DefinitionViolation("{$path}.scale", 'scale_required');
            } else {
                if (! in_array($question->scale->min, self::SCALE_MINIMUMS, true)) {
                    $violations[] = new DefinitionViolation("{$path}.scale.min", 'invalid_scale_min');
                }
                if ($question->scale->max < self::SCALE_MAX_FROM || $question->scale->max > self::SCALE_MAX_TO) {
                    $violations[] = new DefinitionViolation("{$path}.scale.max", 'invalid_scale_max');
                }
            }
        }

        if ($type === QuestionType::StarRating && ($question->stars === null || $question->stars < self::STARS_FROM || $question->stars > self::STARS_TO)) {
            $violations[] = new DefinitionViolation("{$path}.stars", 'invalid_stars');
        }

        if ($type === QuestionType::Grid) {
            if (! $question->grid instanceof GridSettings) {
                $violations[] = new DefinitionViolation("{$path}.grid", 'grid_required');
            } else {
                if ($question->grid->rows === []) {
                    $violations[] = new DefinitionViolation("{$path}.grid.rows", 'too_few_rows');
                }
                if (count($question->grid->columns) < 2) {
                    $violations[] = new DefinitionViolation("{$path}.grid.columns", 'too_few_columns');
                }
                array_push($violations, ...$this->checkOptions($question->grid->rows, "{$path}.grid.rows"));
                array_push($violations, ...$this->checkOptions($question->grid->columns, "{$path}.grid.columns"));
            }
        }

        if ($type === QuestionType::Availability) {
            array_push($violations, ...$this->checkSlots($question->slots, "{$path}.slots"));
        } elseif ($question->slots !== []) {
            $violations[] = new DefinitionViolation("{$path}.slots", 'slots_not_allowed');
        }

        return $violations;
    }

    /**
     * Between SLOTS_MIN and SLOTS_MAX slots, each a whole day or a UTC start with an optional
     * later end, and no date proposed twice.
     *
     * @param  list<Slot>  $slots
     * @return list<DefinitionViolation>
     */
    private function checkSlots(array $slots, string $path): array
    {
        $violations = [];
        if (count($slots) < self::SLOTS_MIN) {
            $violations[] = new DefinitionViolation($path, 'too_few_slots');
        }
        if (count($slots) > self::SLOTS_MAX) {
            $violations[] = new DefinitionViolation($path, 'too_many_slots');
        }

        $ids = [];
        $seen = [];
        foreach ($slots as $index => $slot) {
            $slotPath = "{$path}.{$index}";
            $this->checkId($slot->id, "{$slotPath}.id", $ids, $violations);

            if ($slot->isWholeDay()) {
                if (! $this->isDate($slot->date)) {
                    $violations[] = new DefinitionViolation("{$slotPath}.date", 'invalid_slot_date');
                }
                if ($slot->endsAt !== null) {
                    $violations[] = new DefinitionViolation("{$slotPath}.ends_at", 'invalid_slot_end');
                }
            } else {
                if ($slot->date !== null) {
                    $violations[] = new DefinitionViolation("{$slotPath}.date", 'invalid_slot_date');
                }
                $start = $this->utcInstant($slot->startsAt);
                if (! $start instanceof DateTimeImmutable) {
                    $violations[] = new DefinitionViolation("{$slotPath}.starts_at", 'invalid_slot_start');
                }
                if ($slot->endsAt !== null) {
                    $end = $this->utcInstant($slot->endsAt);
                    if (! $end instanceof DateTimeImmutable) {
                        $violations[] = new DefinitionViolation("{$slotPath}.ends_at", 'invalid_slot_end');
                    } elseif ($start instanceof DateTimeImmutable && $end <= $start) {
                        $violations[] = new DefinitionViolation("{$slotPath}.ends_at", 'slot_ends_before_start');
                    }
                }
            }

            $when = implode('|', [$slot->date ?? '', $slot->startsAt ?? '', $slot->endsAt ?? '']);
            if (isset($seen[$when])) {
                $violations[] = new DefinitionViolation($slotPath, 'duplicate_slot');
            }
            $seen[$when] = true;
        }

        return $violations;
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
     * An instant written as ISO 8601 in UTC ("2026-12-11T16:00:00+00:00"), or null.
     */
    private function utcInstant(?string $text): ?DateTimeImmutable
    {
        if ($text === null) {
            return null;
        }

        $instant = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $text);

        return $instant !== false && $instant->getOffset() === 0 && $instant->format(DateTimeInterface::ATOM) === $text ? $instant : null;
    }

    /**
     * The answer-dependent jumps of a question: only types that canBranch(), one such question
     * per section ($ordinal counts them), each option once, and forward targets.
     *
     * @return list<DefinitionViolation>
     */
    private function checkJumps(SurveyDefinition $definition, int $sectionIndex, Question $question, string $path, int $ordinal): array
    {
        if (! $question->type->canBranch()) {
            return [new DefinitionViolation($path, 'branching_not_allowed')];
        }
        if ($ordinal > 1) {
            return [new DefinitionViolation($path, 'one_branching_question_per_section')];
        }

        $violations = [];
        $optionIds = array_map(static fn (Option $option): string => $option->id, $question->options);
        if ($question->allowOther) {
            $optionIds[] = Submission::OTHER;
        }

        $seen = [];
        foreach ($question->nextByOption as $j => $jump) {
            if (! in_array($jump->optionId, $optionIds, true)) {
                $violations[] = new DefinitionViolation("{$path}.{$j}.option_id", 'unknown_option_target');
            } elseif (isset($seen[$jump->optionId])) {
                $violations[] = new DefinitionViolation("{$path}.{$j}.option_id", 'duplicate_id');
            }
            $seen[$jump->optionId] = true;

            array_push($violations, ...$this->checkTarget($definition, $sectionIndex, $jump->next, "{$path}.{$j}.next"));
        }

        return $violations;
    }

    /**
     * A jump goes to the end or to a later section, so a survey never loops.
     *
     * @return list<DefinitionViolation>
     */
    private function checkTarget(SurveyDefinition $definition, int $sectionIndex, string $target, string $path): array
    {
        if ($target === Section::SUBMIT) {
            return [];
        }

        $index = $definition->sectionIndex($target);
        if ($index === null) {
            return [new DefinitionViolation($path, 'unknown_section')];
        }

        return $index <= $sectionIndex ? [new DefinitionViolation($path, 'jump_backwards')] : [];
    }

    /**
     * @param  list<Option>  $options
     * @return list<DefinitionViolation>
     */
    private function checkOptions(array $options, string $path): array
    {
        $violations = [];
        $ids = [];
        foreach ($options as $index => $option) {
            $this->checkId($option->id, "{$path}.{$index}.id", $ids, $violations);
            if (trim($option->label) === '') {
                $violations[] = new DefinitionViolation("{$path}.{$index}.label", 'required_label');
            }
        }

        return $violations;
    }

    /**
     * @return list<DefinitionViolation>
     */
    private function checkPoll(SurveyDefinition $definition): array
    {
        if (count($definition->questions()) !== 1) {
            return [new DefinitionViolation('sections', 'poll_single_question')];
        }

        $violations = $definition->hasBranching() ? [new DefinitionViolation('sections', 'branching_not_allowed_in_poll')] : [];
        foreach ($definition->sections as $s => $section) {
            foreach ($section->questions as $q => $question) {
                $path = "sections.{$s}.questions.{$q}";
                if (! in_array($question->type, [QuestionType::SingleChoice, QuestionType::MultipleChoice], true)) {
                    $violations[] = new DefinitionViolation("{$path}.type", 'poll_type');
                }
                if ($question->allowOther) {
                    $violations[] = new DefinitionViolation("{$path}.allow_other", 'other_not_allowed');
                }
            }
        }

        return $violations;
    }

    /**
     * One availability question and nothing else: the survey is that question.
     *
     * @return list<DefinitionViolation>
     */
    private function checkAvailability(SurveyDefinition $definition): array
    {
        if (count($definition->questions()) !== 1) {
            return [new DefinitionViolation('sections', 'availability_single_question')];
        }

        $violations = [];
        foreach ($definition->sections as $s => $section) {
            foreach ($section->questions as $q => $question) {
                if ($question->type !== QuestionType::Availability) {
                    $violations[] = new DefinitionViolation("sections.{$s}.questions.{$q}.type", 'availability_type');
                }
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, true>  $seen
     * @param  list<DefinitionViolation>  $violations
     */
    private function checkId(string $id, string $path, array &$seen, array &$violations): void
    {
        if (trim($id) === '') {
            $violations[] = new DefinitionViolation($path, 'required_id');

            return;
        }

        if (isset($seen[$id])) {
            $violations[] = new DefinitionViolation($path, 'duplicate_id');
        }
        $seen[$id] = true;
    }

    /**
     * @return array<mixed>
     */
    private function withoutHelpTexts(SurveyDefinition $definition): array
    {
        $data = $definition->toArray();
        foreach ($data['sections'] as $s => $section) {
            /** @var list<array<string, mixed>> $questions */
            $questions = $section['questions'];
            foreach (array_keys($questions) as $q) {
                $data['sections'][$s]['questions'][$q]['help'] = null;
            }
        }

        return $data;
    }

    /**
     * @param  list<DefinitionViolation>  $violations
     *
     * @throws InvalidSurveyDefinitionException
     */
    private function throwIfAny(array $violations): void
    {
        if ($violations !== []) {
            throw InvalidSurveyDefinitionException::withViolations($violations);
        }
    }
}
