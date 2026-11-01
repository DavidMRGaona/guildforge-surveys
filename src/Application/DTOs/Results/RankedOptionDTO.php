<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

final readonly class RankedOptionDTO
{
    /**
     * @param  ?float  $meanPosition  null while nobody has ranked it
     * @param  array<int, int>  $positions  place (1 = first) => answers, every place present
     */
    public function __construct(
        public string $optionId,
        public string $label,
        public int $points,
        public ?float $meanPosition,
        public array $positions,
    ) {}
}
