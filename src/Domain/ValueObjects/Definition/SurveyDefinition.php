<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;

/**
 * The content of a survey or template: sections holding questions, and the quiz settings when it
 * is a quiz (its scored questions carry their correct options and points).
 *
 * fromArray() only checks the shape (types and known question types); business rules
 * live in SurveyDefinitionValidator, the single validator every editor goes through.
 */
final readonly class SurveyDefinition
{
    /**
     * @param  list<Section>  $sections
     */
    public function __construct(
        public array $sections,
        public ?QuizSettings $quiz = null,
    ) {}

    /**
     * @param  array<mixed>  $data
     *
     * @throws InvalidSurveyDefinitionException when the array does not have the definition's shape
     */
    public static function fromArray(array $data): self
    {
        return (new DefinitionArrayReader)->read($data);
    }

    /**
     * @return array{sections: list<array<string, mixed>>, quiz: array{show_correct_answers: bool}|null}
     */
    public function toArray(): array
    {
        return [
            'sections' => array_map(static fn (Section $section): array => $section->toArray(), $this->sections),
            'quiz' => $this->quiz?->toArray(),
        ];
    }

    /**
     * The definition without what whoever answers must not see: the correct options and points.
     */
    public function withoutAnswers(): self
    {
        $data = $this->toArray();
        foreach ($data['sections'] as $s => $section) {
            /** @var list<array<string, mixed>> $questions */
            $questions = $section['questions'];
            foreach (array_keys($questions) as $q) {
                $data['sections'][$s]['questions'][$q]['correct_option_ids'] = [];
                $data['sections'][$s]['questions'][$q]['points'] = null;
            }
        }

        return self::fromArray($data);
    }

    /**
     * Every question, in order.
     *
     * @return list<Question>
     */
    public function questions(): array
    {
        $questions = [];
        foreach ($this->sections as $section) {
            foreach ($section->questions as $question) {
                $questions[] = $question;
            }
        }

        return $questions;
    }

    /**
     * Position of the section with that id, or null when there is none.
     */
    public function sectionIndex(string $id): ?int
    {
        foreach ($this->sections as $index => $section) {
            if ($section->id === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Whether any section or answer sends the respondent somewhere other than the next section.
     */
    public function hasBranching(): bool
    {
        return array_any($this->sections, static fn (Section $section): bool => $section->next !== null || $section->branchingQuestion() instanceof Question);
    }

    /**
     * The questions whose answer decides the next section, in order.
     *
     * @return list<string>
     */
    public function branchingQuestionIds(): array
    {
        $ids = [];
        foreach ($this->sections as $section) {
            $question = $section->branchingQuestion();
            if ($question instanceof Question) {
                $ids[] = $question->id;
            }
        }

        return $ids;
    }

    public function question(string $id): ?Question
    {
        foreach ($this->questions() as $question) {
            if ($question->id === $id) {
                return $question;
            }
        }

        return null;
    }
}
