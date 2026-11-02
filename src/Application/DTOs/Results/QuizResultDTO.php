<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

/**
 * A quiz's scores as a whole. On an anonymous survey nothing is filled in while a scored question
 * is below the minimum (a score would give its answers away), and nobody is named.
 */
final readonly class QuizResultDTO
{
    /**
     * @param  list<QuizScoreCountDTO>  $distribution  how many responses got each score, lowest first
     * @param  list<QuizStandingDTO>|null  $leaderboard  only on named surveys, highest first
     */
    public function __construct(
        public int $maxPoints,
        public ?float $mean = null,
        public array $distribution = [],
        public ?array $leaderboard = null,
        public bool $belowMinimum = false,
    ) {}
}
