<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

/**
 * One bar of a chart: an option, a scale value, a day or an hour.
 */
final readonly class ResultBucketDTO
{
    /**
     * @param  string|null  $label  null for "Other", which the presentation names
     * @param  list<string>  $voters  names, only on named polls
     */
    public function __construct(
        public string $key,
        public ?string $label,
        public int $count,
        public array $voters = [],
    ) {}
}
