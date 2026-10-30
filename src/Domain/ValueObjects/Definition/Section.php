<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

final readonly class Section
{
    /**
     * @param  list<Question>  $questions
     */
    public function __construct(
        public string $id,
        public ?string $title,
        public ?string $description,
        public array $questions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'questions' => array_map(static fn (Question $question): array => $question->toArray(), $this->questions),
        ];
    }
}
