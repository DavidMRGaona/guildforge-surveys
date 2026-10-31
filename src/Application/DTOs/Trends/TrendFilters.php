<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

use DateTimeImmutable;

/**
 * The cut of a template's trends. Dates are days in the display time zone, both included.
 * Built from a query string or a panel form, so anything it cannot read falls back to "all".
 */
final readonly class TrendFilters
{
    public function __construct(
        public ?string $subjectType = null,
        public ?string $organizerId = null,
        public ?string $gameSystem = null,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public TrendPeriod $period = TrendPeriod::Quarter,
        public TrendComparison $compareBy = TrendComparison::None,
    ) {}

    /**
     * @param  array<mixed>  $input  keys type, organizer, system, from and to (Y-m-d), period, compare
     */
    public static function fromInput(array $input): self
    {
        $from = self::day($input['from'] ?? null);
        $to = self::day($input['to'] ?? null);

        if ($from instanceof DateTimeImmutable && $to instanceof DateTimeImmutable && $from > $to) {
            $from = $to = null;
        }

        return new self(
            subjectType: self::text($input['type'] ?? null),
            organizerId: self::text($input['organizer'] ?? null),
            gameSystem: self::text($input['system'] ?? null),
            from: $from,
            to: $to,
            period: TrendPeriod::tryFrom(self::text($input['period'] ?? null) ?? '') ?? TrendPeriod::Quarter,
            compareBy: TrendComparison::tryFrom(self::text($input['compare'] ?? null) ?? '') ?? TrendComparison::None,
        );
    }

    /**
     * The same cut without anything about organizers, for an organizer looking at their own.
     */
    public function withoutOrganizer(): self
    {
        return new self(
            subjectType: $this->subjectType,
            gameSystem: $this->gameSystem,
            from: $this->from,
            to: $this->to,
            period: $this->period,
            compareBy: $this->compareBy === TrendComparison::Organizer ? TrendComparison::None : $this->compareBy,
        );
    }

    /**
     * @return array{type: ?string, organizer: ?string, system: ?string, from: ?string, to: ?string, period: string, compare: string}
     */
    public function toInput(): array
    {
        return [
            'type' => $this->subjectType,
            'organizer' => $this->organizerId,
            'system' => $this->gameSystem,
            'from' => $this->from?->format('Y-m-d'),
            'to' => $this->to?->format('Y-m-d'),
            'period' => $this->period->value,
            'compare' => $this->compareBy->value,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function day(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $day instanceof DateTimeImmutable && $day->format('Y-m-d') === $value ? $day : null;
    }
}
