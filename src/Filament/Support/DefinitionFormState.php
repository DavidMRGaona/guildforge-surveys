<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Support;

use Illuminate\Support\Str;
use Modules\Surveys\Application\Services\AvailabilitySlotFormatter;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\OptionJump;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;

/**
 * Converts between a SurveyDefinition and the state of the panel's definition editor:
 * a Repeater of sections, each with a Builder of questions (one block per question type).
 * Filament keys list items by random strings, so domain paths (by index) are translated
 * to form paths to show each validation error on its own field. A question's jumps sit on
 * its options ("next") and "Other" ("other_next"), behind a switch ("branches"): with
 * the switch off they are left out of the definition. Availability slots are edited as typed
 * in the site's zone (day, start, end) and stored as AvailabilitySlotFormatter reads them.
 */
final class DefinitionFormState
{
    /** Domain field => block field, for settings the editor flattens into the block. */
    private const array FLATTENED = [
        'scale.min' => 'scale_min',
        'scale.max' => 'scale_max',
        'scale.min_label' => 'scale_min_label',
        'scale.max_label' => 'scale_max_label',
        'grid.rows' => 'grid_rows',
        'grid.columns' => 'grid_columns',
    ];

    /** Slot field => its editor field. */
    private const array SLOT_FIELDS = [
        'id' => 'day',
        'date' => 'day',
        'starts_at' => 'start',
        'ends_at' => 'end',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function fromDefinition(SurveyDefinition $definition): array
    {
        $sections = [];
        foreach ($definition->sections as $section) {
            $questions = [];
            foreach ($section->questions as $question) {
                $questions[self::itemKey()] = ['type' => $question->type->value, 'data' => self::questionData($question)];
            }

            $sections[self::itemKey()] = [
                'id' => $section->id,
                'title' => $section->title,
                'description' => $section->description,
                'next' => $section->next,
                'questions' => $questions,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<mixed>  $state
     *
     * @throws InvalidSurveyDefinitionException when the state does not have a definition's shape
     */
    public static function toDefinition(array $state): SurveyDefinition
    {
        $sections = [];
        foreach ($state as $section) {
            $section = is_array($section) ? $section : [];
            $questions = [];
            foreach (self::items($section['questions'] ?? []) as $block) {
                $questions[] = self::questionArray(is_string($block['type'] ?? null) ? $block['type'] : '', is_array($block['data'] ?? null) ? $block['data'] : []);
            }

            $sections[] = [
                'id' => self::string($section['id'] ?? null),
                'title' => self::nullableString($section['title'] ?? null),
                'description' => self::nullableString($section['description'] ?? null),
                'next' => self::nullableString($section['next'] ?? null),
                'questions' => $questions,
            ];
        }

        return SurveyDefinition::fromArray(['sections' => $sections]);
    }

    /**
     * The form path of a domain path such as "sections.0.questions.2.options.1.label",
     * relative to the editor's state; as far as the state allows when an item is missing.
     * Errors on fields the editor does not show (hidden ids, the block type) land on the
     * item's visible title or label instead.
     *
     * @param  array<mixed>  $state
     */
    public static function formPath(array $state, string $domainPath): string
    {
        $tokens = explode('.', $domainPath);
        $path = [array_shift($tokens)];

        $section = self::itemAt($state, $tokens);
        if ($section === null) {
            return implode('.', $path);
        }
        $path[] = $section['key'];

        $next = array_shift($tokens);
        if ($next !== 'questions') {
            return implode('.', [...$path, $next === 'id' ? 'title' : $next, ...$tokens]);
        }
        $path[] = 'questions';
        $questions = is_array($section['item']['questions'] ?? null) ? $section['item']['questions'] : [];
        $question = self::itemAt($questions, $tokens);
        if ($question === null) {
            return implode('.', $path);
        }
        $path[] = $question['key'];
        $path[] = 'data';

        $field = implode('.', array_slice($tokens, 0, 2));
        $data = is_array($question['item']['data'] ?? null) ? $question['item']['data'] : [];
        if (($tokens[0] ?? null) === 'next_by_option') {
            return implode('.', [...$path, self::jumpField($data, $tokens[1] ?? null)]);
        }
        if (in_array($tokens[0] ?? null, ['id', 'type'], true)) {
            $path[] = 'title';
            $tokens = [];
        } elseif (isset(self::FLATTENED[$field])) {
            $path[] = self::FLATTENED[$field];
            $tokens = array_slice($tokens, 2);
        } else {
            $path[] = (string) array_shift($tokens);
        }

        // An item of a list inside the block (options, grid rows or columns, slots)
        $listField = end($path);
        $list = $data[$listField] ?? null;
        if (is_array($list) && $tokens !== []) {
            $item = self::itemAt($list, $tokens);
            if ($item !== null) {
                $path[] = $item['key'];
                $tokens = match (true) {
                    $listField === 'slots' => [self::SLOT_FIELDS[$tokens[0] ?? 'id'] ?? 'day'],
                    $tokens === ['id'] => ['label'],
                    default => $tokens,
                };
            }
        }

        return implode('.', [...$path, ...$tokens]);
    }

    /**
     * A copy with new ids for sections and questions but the same keys, options, grid rows and
     * columns: trends match questions by key and options and rows by id with the template's,
     * so a survey made from a template stays comparable with the others made from it. Jumps
     * follow their sections to the new ids.
     */
    public static function withFreshQuestionIds(SurveyDefinition $definition): SurveyDefinition
    {
        $sectionIds = [];
        foreach ($definition->sections as $section) {
            $sectionIds[$section->id] = (string) Str::uuid();
        }
        $renamed = static fn (?string $target): ?string => $target === null ? null : ($sectionIds[$target] ?? $target);

        return new SurveyDefinition(array_map(
            static fn (Section $section): Section => new Section(
                id: $sectionIds[$section->id],
                title: $section->title,
                description: $section->description,
                questions: array_map(
                    static fn (Question $question): Question => new Question(
                        id: (string) Str::uuid(),
                        key: $question->key,
                        type: $question->type,
                        title: $question->title,
                        help: $question->help,
                        required: $question->required,
                        options: $question->options,
                        allowOther: $question->allowOther,
                        scale: $question->scale,
                        stars: $question->stars,
                        grid: $question->grid,
                        nextByOption: array_map(
                            static fn (OptionJump $jump): OptionJump => new OptionJump($jump->optionId, (string) $renamed($jump->next)),
                            $question->nextByOption,
                        ),
                        slots: $question->slots,
                    ),
                    $section->questions,
                ),
                next: $renamed($section->next),
            ),
            $definition->sections,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private static function questionData(Question $question): array
    {
        return [
            'id' => $question->id,
            'key' => $question->key,
            'title' => $question->title,
            'help' => $question->help,
            'required' => $question->required,
            'options' => self::keyedChoices($question),
            'allow_other' => $question->allowOther,
            'branches' => $question->nextByOption !== [],
            'other_next' => $question->nextFor(Submission::OTHER),
            'scale_min' => $question->scale?->min,
            'scale_max' => $question->scale?->max,
            'scale_min_label' => $question->scale?->minLabel,
            'scale_max_label' => $question->scale?->maxLabel,
            'stars' => $question->stars,
            'grid_rows' => self::keyedOptions($question->grid->rows ?? []),
            'grid_columns' => self::keyedOptions($question->grid->columns ?? []),
            'slots' => self::keyedSlots($question->slots),
        ];
    }

    /**
     * @param  list<Slot>  $slots
     * @return array<string, array{id: string, day: string, start: ?string, end: ?string}>
     */
    private static function keyedSlots(array $slots): array
    {
        if ($slots === []) {
            return [];
        }

        $formatter = app(AvailabilitySlotFormatter::class);
        $keyed = [];
        foreach ($slots as $slot) {
            $keyed[self::itemKey()] = ['id' => $slot->id, 'day' => $formatter->day($slot), 'start' => $formatter->start($slot), 'end' => $formatter->end($slot)];
        }

        return $keyed;
    }

    /**
     * @return list<array{id: string, date: ?string, starts_at: ?string, ends_at: ?string}>
     */
    private static function slotList(mixed $items): array
    {
        if (self::items($items) === []) {
            return [];
        }

        $formatter = app(AvailabilitySlotFormatter::class);

        return array_map(
            static fn (array $item): array => $formatter->fromWallTime(
                self::string($item['id'] ?? null),
                // The date picker may hold a time too ("2030-01-12 00:00:00")
                substr(self::string($item['day'] ?? null), 0, 10),
                self::nullableString($item['start'] ?? null),
                self::nullableString($item['end'] ?? null),
            )->toArray(),
            self::items($items),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function questionArray(string $typeValue, array $data): array
    {
        $type = QuestionType::tryFrom($typeValue);

        return [
            'id' => self::string($data['id'] ?? null),
            'key' => self::nullableString($data['key'] ?? null),
            'type' => $typeValue,
            'title' => self::string($data['title'] ?? null),
            'help' => self::nullableString($data['help'] ?? null),
            'required' => (bool) ($data['required'] ?? false),
            'options' => $type?->hasOptions() === true ? self::optionList($data['options'] ?? []) : [],
            'allow_other' => $type?->allowsOther() === true && (bool) ($data['allow_other'] ?? false),
            'scale' => $type === QuestionType::LinearScale ? [
                'min' => self::int($data['scale_min'] ?? null),
                'max' => self::int($data['scale_max'] ?? null),
                'min_label' => self::nullableString($data['scale_min_label'] ?? null),
                'max_label' => self::nullableString($data['scale_max_label'] ?? null),
            ] : null,
            'stars' => $type === QuestionType::StarRating ? self::int($data['stars'] ?? null) : null,
            'grid' => $type === QuestionType::Grid ? [
                'rows' => self::optionList($data['grid_rows'] ?? []),
                'columns' => self::optionList($data['grid_columns'] ?? []),
            ] : null,
            'next_by_option' => $type?->canBranch() === true && (bool) ($data['branches'] ?? false) ? array_map(static fn (array $jump): array => ['option_id' => $jump['option_id'], 'next' => $jump['next']], self::jumps($data)) : [],
            'slots' => $type === QuestionType::Availability ? self::slotList($data['slots'] ?? []) : [],
        ];
    }

    /**
     * The jumps the block's options and "Other" hold, in the order the definition keeps
     * them: options first, then "Other". jumpField() reads them back in the same order.
     *
     * @param  array<mixed>  $data
     * @return list<array{option_id: string, next: string, field: string}>
     */
    private static function jumps(array $data): array
    {
        $jumps = [];
        foreach (is_array($data['options'] ?? null) ? $data['options'] : [] as $key => $option) {
            $next = is_array($option) ? self::nullableString($option['next'] ?? null) : null;
            if ($next !== null && $next !== '') {
                $jumps[] = ['option_id' => self::string($option['id'] ?? null), 'next' => $next, 'field' => "options.{$key}.next"];
            }
        }

        $otherNext = self::nullableString($data['other_next'] ?? null);
        if ((bool) ($data['allow_other'] ?? false) && $otherNext !== null && $otherNext !== '') {
            $jumps[] = ['option_id' => Submission::OTHER, 'next' => $otherNext, 'field' => 'other_next'];
        }

        return $jumps;
    }

    /**
     * The block field of the jump at a domain index: its option's select, or the switch
     * for errors about the question's jumps as a whole.
     *
     * @param  array<mixed>  $data
     */
    private static function jumpField(array $data, ?string $index): string
    {
        $jump = $index !== null && ctype_digit($index) ? (self::jumps($data)[(int) $index] ?? null) : null;

        return $jump['field'] ?? 'branches';
    }

    /**
     * Options with the section each one leads to (null: the section's own next).
     *
     * @return array<string, array{id: string, label: string, next: ?string}>
     */
    private static function keyedChoices(Question $question): array
    {
        $keyed = [];
        foreach ($question->options as $option) {
            $keyed[self::itemKey()] = [...$option->toArray(), 'next' => $question->nextFor($option->id)];
        }

        return $keyed;
    }

    /**
     * @param  list<Option>  $options
     * @return array<string, array{id: string, label: string}>
     */
    private static function keyedOptions(array $options): array
    {
        $keyed = [];
        foreach ($options as $option) {
            $keyed[self::itemKey()] = $option->toArray();
        }

        return $keyed;
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private static function optionList(mixed $items): array
    {
        return array_map(
            static fn (array $item): array => ['id' => self::string($item['id'] ?? null), 'label' => self::string($item['label'] ?? null)],
            self::items($items),
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private static function items(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }

    /**
     * The item at the index the tokens start with, and its Filament key.
     *
     * @param  array<mixed>  $list
     * @param  list<string>  $tokens  consumed by one when an index is read
     * @return array{key: string, item: array<mixed>}|null
     */
    private static function itemAt(array $list, array &$tokens): ?array
    {
        if ($tokens === [] || ! ctype_digit($tokens[0])) {
            return null;
        }

        $index = (int) array_shift($tokens);
        $keys = array_keys($list);
        if (! isset($keys[$index])) {
            return null;
        }

        $item = $list[$keys[$index]];

        return ['key' => (string) $keys[$index], 'item' => is_array($item) ? $item : []];
    }

    private static function itemKey(): string
    {
        return (string) Str::uuid();
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
