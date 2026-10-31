<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

use DateTimeImmutable;

/**
 * How trends group surveys in time. Keys sort as strings in time order, so the axis can be
 * filled from the first to the last one.
 */
enum TrendPeriod: string
{
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    /**
     * The period of a date already in the display time zone.
     */
    public function keyFor(DateTimeImmutable $displayDate): string
    {
        return match ($this) {
            self::Month => $displayDate->format('Y-m'),
            self::Quarter => $displayDate->format('Y').'-Q'.intdiv((int) $displayDate->format('n') + 2, 3),
            self::Year => $displayDate->format('Y'),
        };
    }

    /**
     * How a key reads on a chart axis: «ene 2027», «T1 2027», «2027».
     */
    public function label(string $key): string
    {
        [$year, $part] = $this->parts($key);

        return match ($this) {
            self::Month => (string) __('surveys::messages.trends.month', ['month' => __("surveys::messages.trends.months.{$part}"), 'year' => $year]),
            self::Quarter => (string) __('surveys::messages.trends.quarter', ['quarter' => $part, 'year' => $year]),
            self::Year => (string) $year,
        };
    }

    /**
     * Every key from the first to the last, both included, empty periods too.
     *
     * @return list<string>
     */
    public function between(string $first, string $last): array
    {
        [$year, $part] = $this->parts($first);
        [$lastYear, $lastPart] = $this->parts($last);
        $perYear = match ($this) {
            self::Month => 12,
            self::Quarter => 4,
            self::Year => 1,
        };

        $keys = [];
        while ($year < $lastYear || ($year === $lastYear && $part <= $lastPart)) {
            $keys[] = match ($this) {
                self::Month => sprintf('%04d-%02d', $year, $part),
                self::Quarter => sprintf('%04d-Q%d', $year, $part),
                self::Year => sprintf('%04d', $year),
            };

            if (++$part > $perYear) {
                $part = 1;
                $year++;
            }
        }

        return $keys;
    }

    /**
     * @return array{int, int} year and month, quarter or 1
     */
    private function parts(string $key): array
    {
        $year = (int) substr($key, 0, 4);

        return match ($this) {
            self::Month => [$year, (int) substr($key, 5, 2)],
            self::Quarter => [$year, (int) substr($key, 6, 1)],
            self::Year => [$year, 1],
        };
    }
}
