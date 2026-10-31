<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

/**
 * What the filters of a template's trends can choose from, taken from its surveys.
 */
final readonly class TrendFilterOptionsDTO
{
    /**
     * @param  list<array{value: string, label: string}>  $subjectTypes
     * @param  list<array{value: string, label: string}>  $organizers  empty for an organizer's own trends
     * @param  list<string>  $gameSystems
     */
    public function __construct(
        public array $subjectTypes,
        public array $organizers,
        public array $gameSystems,
    ) {}
}
