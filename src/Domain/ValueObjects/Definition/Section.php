<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

/**
 * A page of questions. $next says where to go after it: null for the following section,
 * a later section's id, or SUBMIT to end the survey. A question with jumps overrides it.
 */
final readonly class Section
{
    /** Target that ends the survey instead of going to a section. */
    public const string SUBMIT = '__submit__';

    /**
     * @param  list<Question>  $questions
     */
    public function __construct(
        public string $id,
        public ?string $title,
        public ?string $description,
        public array $questions,
        public ?string $next = null,
    ) {}

    /**
     * The question whose answer decides the next section, if any.
     */
    public function branchingQuestion(): ?Question
    {
        foreach ($this->questions as $question) {
            if ($question->nextByOption !== []) {
                return $question;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'next' => $this->next,
            'questions' => array_map(static fn (Question $question): array => $question->toArray(), $this->questions),
        ];
    }
}
