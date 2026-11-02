<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

final readonly class QuizScoreCountDTO
{
    public function __construct(
        public int $points,
        public int $responses,
    ) {}
}
