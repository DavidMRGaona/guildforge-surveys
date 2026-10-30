<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Support;

use Illuminate\Support\Str;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\ValueObjects\Definition\GridSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;

/**
 * Converts between a SurveyDefinition and the state of the panel's definition editor:
 * a Repeater of sections, each with a Builder of questions (one block per question type).
 * Filament keys list items by random strings, so domain paths (by index) are translated
 * to form paths to show each validation error on its own field.
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
                'questions' => $questions,
            ];
        }

        return SurveyDefinition::fromArray(['sections' => $sections]);
    }

    /**
     * The form path of a domain path such as "sections.0.questions.2.options.1.label",
     * relative to the editor's state; as far as the state allows when an item is missing.
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

        if (array_shift($tokens) !== 'questions') {
            return implode('.', [...$path, ...$tokens]);
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
        if (isset(self::FLATTENED[$field])) {
            $path[] = self::FLATTENED[$field];
            $tokens = array_slice($tokens, 2);
        } else {
            $path[] = (string) array_shift($tokens);
        }

        // An item of a list inside the block (options, grid rows or columns)
        $data = is_array($question['item']['data'] ?? null) ? $question['item']['data'] : [];
        $list = $data[end($path)] ?? null;
        if (is_array($list) && $tokens !== []) {
            $item = self::itemAt($list, $tokens);
            if ($item !== null) {
                $path[] = $item['key'];
            }
        }

        return implode('.', [...$path, ...$tokens]);
    }

    /**
     * A copy with new ids for sections, questions and options but the same keys, so a
     * survey made from a template stays comparable with the others made from it.
     */
    public static function withFreshIds(SurveyDefinition $definition): SurveyDefinition
    {
        return new SurveyDefinition(array_map(
            static fn (Section $section): Section => new Section(
                id: (string) Str::uuid(),
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
                        options: self::freshOptions($question->options),
                        allowOther: $question->allowOther,
                        scale: $question->scale,
                        stars: $question->stars,
                        grid: $question->grid instanceof GridSettings ? new GridSettings(self::freshOptions($question->grid->rows), self::freshOptions($question->grid->columns)) : null,
                    ),
                    $section->questions,
                ),
            ),
            $definition->sections,
        ));
    }

    /**
     * @param  list<Option>  $options
     * @return list<Option>
     */
    private static function freshOptions(array $options): array
    {
        return array_map(static fn (Option $option): Option => new Option((string) Str::uuid(), $option->label), $options);
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
            'options' => self::keyedOptions($question->options),
            'allow_other' => $question->allowOther,
            'scale_min' => $question->scale?->min,
            'scale_max' => $question->scale?->max,
            'scale_min_label' => $question->scale?->minLabel,
            'scale_max_label' => $question->scale?->maxLabel,
            'stars' => $question->stars,
            'grid_rows' => self::keyedOptions($question->grid->rows ?? []),
            'grid_columns' => self::keyedOptions($question->grid->columns ?? []),
        ];
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
        ];
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
