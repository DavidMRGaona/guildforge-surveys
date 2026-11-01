<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Submission;

/**
 * What one response scored in a quiz.
 */
final readonly class QuizScore
{
    /**
     * @param  array<string, bool>  $correct  scored question id => whether it got it right
     */
    public function __construct(
        public int $points,
        public int $maxPoints,
        public array $correct,
    ) {}
}
