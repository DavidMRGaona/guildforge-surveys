<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

/**
 * A line or a row of bars: everything (id null), or one organizer, game system or kind of
 * subject ('' for surveys without one). The grouped rest has a null id too.
 */
final readonly class TrendSeriesDTO
{
    /**
     * @param  list<TrendPointDTO>  $points
     */
    public function __construct(
        public ?string $id,
        public string $label,
        public array $points,
    ) {}
}
