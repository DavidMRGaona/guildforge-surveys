<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

/**
 * One cut: a series in a period (or over the whole range). Below the minimum it carries only
 * how many answered, never a mean or shares.
 */
final readonly class TrendPointDTO
{
    /**
     * @param  string  $period  a TrendPeriod key, or QuestionTrendDTO::TOTAL
     * @param  array<string, float>  $shares  option or column id => share of the answers (0 to 1)
     */
    public function __construct(
        public string $period,
        public int $answered,
        public bool $belowMinimum,
        public ?float $mean = null,
        public array $shares = [],
    ) {}
}
