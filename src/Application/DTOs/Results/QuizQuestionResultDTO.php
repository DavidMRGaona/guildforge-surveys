<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

/**
 * How a scored question went: how many of those who answered it got it right.
 */
final readonly class QuizQuestionResultDTO
{
    /**
     * @param  list<string>  $correctOptionIds
     * @param  ?float  $rate  correct / answered, null while nobody answered
     */
    public function __construct(
        public array $correctOptionIds,
        public int $points,
        public int $correct,
        public ?float $rate,
    ) {}
}
