<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;

/**
 * Turns the stored or submitted array into a SurveyDefinition, collecting every shape
 * problem before failing. Missing optional fields take their defaults; a field of the
 * wrong type is "malformed". Empty values are left to SurveyDefinitionValidator.
 *
 * @internal used by SurveyDefinition::fromArray()
 */
final class DefinitionArrayReader
{
    /** @var list<DefinitionViolation> */
    private array $violations = [];

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidSurveyDefinitionException
     */
    public function read(array $data): SurveyDefinition
    {
        $sections = [];
        foreach ($this->list($data, 'sections', 'sections') as $index => $section) {
            $sections[] = $this->section($section, "sections.{$index}");
        }

        if ($this->violations !== []) {
            throw InvalidSurveyDefinitionException::withViolations($this->violations);
        }

        return new SurveyDefinition($sections);
    }

    /**
     * @param  array<mixed>  $data
     */
    private function section(array $data, string $path): Section
    {
        $questions = [];
        foreach ($this->list($data, 'questions', "{$path}.questions") as $index => $question) {
            $read = $this->question($question, "{$path}.questions.{$index}");
            if ($read instanceof Question) {
                $questions[] = $read;
            }
        }

        return new Section(
            id: $this->string($data, 'id', $path),
            title: $this->nullableString($data, 'title', $path),
            description: $this->nullableString($data, 'description', $path),
            questions: $questions,
        );
    }

    /**
     * @param  array<mixed>  $data
     */
    private function question(array $data, string $path): ?Question
    {
        $typeValue = $data['type'] ?? null;
        $type = is_string($typeValue) ? QuestionType::tryFrom($typeValue) : null;
        if ($type === null) {
            $this->violate("{$path}.type", 'unknown_type');

            return null;
        }

        $scale = $this->nullableMap($data, 'scale', $path);
        $grid = $this->nullableMap($data, 'grid', $path);
        $stars = $data['stars'] ?? null;
        if ($stars !== null && ! is_int($stars)) {
            $this->violate("{$path}.stars", 'malformed');
            $stars = null;
        }

        return new Question(
            id: $this->string($data, 'id', $path),
            key: $this->nullableString($data, 'key', $path),
            type: $type,
            title: $this->string($data, 'title', $path),
            help: $this->nullableString($data, 'help', $path),
            required: $this->bool($data, 'required', $path),
            options: $this->options($data, 'options', "{$path}.options"),
            allowOther: $this->bool($data, 'allow_other', $path),
            scale: $scale === null ? null : new ScaleSettings(
                min: $this->int($scale, 'min', "{$path}.scale"),
                max: $this->int($scale, 'max', "{$path}.scale"),
                minLabel: $this->nullableString($scale, 'min_label', "{$path}.scale"),
                maxLabel: $this->nullableString($scale, 'max_label', "{$path}.scale"),
            ),
            stars: $stars,
            grid: $grid === null ? null : new GridSettings(
                rows: $this->options($grid, 'rows', "{$path}.grid.rows"),
                columns: $this->options($grid, 'columns', "{$path}.grid.columns"),
            ),
        );
    }

    /**
     * @param  array<mixed>  $data
     * @return list<Option>
     */
    private function options(array $data, string $key, string $path): array
    {
        $options = [];
        foreach ($this->list($data, $key, $path) as $index => $option) {
            $options[] = new Option(
                id: $this->string($option, 'id', "{$path}.{$index}"),
                label: $this->string($option, 'label', "{$path}.{$index}"),
            );
        }

        return $options;
    }

    /**
     * A list of arrays; anything else in it is reported and skipped.
     *
     * @param  array<mixed>  $data
     * @return array<int, array<mixed>>
     */
    private function list(array $data, string $key, string $path): array
    {
        $value = $data[$key] ?? [];
        if (! is_array($value) || ! array_is_list($value)) {
            $this->violate($path, 'malformed');

            return [];
        }

        $items = [];
        foreach ($value as $index => $item) {
            if (! is_array($item)) {
                $this->violate("{$path}.{$index}", 'malformed');

                continue;
            }
            $items[$index] = $item;
        }

        return $items;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>|null
     */
    private function nullableMap(array $data, string $key, string $path): ?array
    {
        $value = $data[$key] ?? null;
        if ($value === null || is_array($value)) {
            return $value;
        }

        $this->violate("{$path}.{$key}", 'malformed');

        return null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function string(array $data, string $key, string $path): string
    {
        return $this->nullableString($data, $key, $path) ?? '';
    }

    /**
     * @param  array<mixed>  $data
     */
    private function nullableString(array $data, string $key, string $path): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || is_string($value)) {
            return $value;
        }

        $this->violate("{$path}.{$key}", 'malformed');

        return null;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function bool(array $data, string $key, string $path): bool
    {
        $value = $data[$key] ?? false;
        if (is_bool($value)) {
            return $value;
        }

        $this->violate("{$path}.{$key}", 'malformed');

        return false;
    }

    /**
     * @param  array<mixed>  $data
     */
    private function int(array $data, string $key, string $path): int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        $this->violate("{$path}.{$key}", 'malformed');

        return 0;
    }

    private function violate(string $path, string $messageKey): void
    {
        $this->violations[] = new DefinitionViolation($path, $messageKey);
    }
}
