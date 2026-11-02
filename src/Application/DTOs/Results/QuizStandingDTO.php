<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

/**
 * One person's place in a named quiz: people on the same score share it.
 */
final readonly class QuizStandingDTO
{
    public function __construct(
        public string $name,
        public int $points,
        public int $rank,
    ) {}
}
