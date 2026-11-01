<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

/**
 * A date proposed by an availability question: a whole day (a calendar date, with no zone) or a
 * time slot, whose start and optional end are instants in UTC (ISO 8601) so they keep meaning the
 * same moment whatever zone the site shows. Exactly one of $date and $startsAt is set.
 */
final readonly class Slot
{
    public function __construct(
        public string $id,
        public ?string $date = null,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
    ) {}

    public function isWholeDay(): bool
    {
        return $this->startsAt === null;
    }

    /**
     * @return array{id: string, date: ?string, starts_at: ?string, ends_at: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'date' => $this->date, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt];
    }
}
