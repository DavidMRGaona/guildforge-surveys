<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

/**
 * A survey that is a quiz: its choice questions may mark their correct options and give points.
 * $showCorrectAnswers says whether whoever answers sees, on sending, what they got right.
 */
final readonly class QuizSettings
{
    public function __construct(
        public bool $showCorrectAnswers = true,
    ) {}

    /**
     * @return array{show_correct_answers: bool}
     */
    public function toArray(): array
    {
        return ['show_correct_answers' => $this->showCorrectAnswers];
    }
}
