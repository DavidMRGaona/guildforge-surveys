<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

/**
 * The results of a ranking: each option with its Borda points (as many points as places for
 * the first, one fewer for each place below), its mean place and how often it got each place.
 */
final readonly class RankingResultDTO
{
    /**
     * @param  int  $positions  places to fill, one per option
     * @param  list<RankedOptionDTO>  $options  best first: most points, then the better mean place, then the survey's order
     */
    public function __construct(
        public int $positions,
        public array $options,
    ) {}
}
