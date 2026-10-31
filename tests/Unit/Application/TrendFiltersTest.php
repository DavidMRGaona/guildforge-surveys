<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Unit\Application;

use DateTimeImmutable;
use DateTimeZone;
use Modules\Surveys\Application\DTOs\Trends\TrendComparison;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\DTOs\Trends\TrendPeriod;
use PHPUnit\Framework\TestCase;

final class TrendFiltersTest extends TestCase
{
    public function test_from_input_reads_every_filter(): void
    {
        $filters = TrendFilters::fromInput([
            'type' => 'game-table',
            'organizer' => 'user-1',
            'system' => 'Dungeons & Dragons',
            'from' => '2027-01-01',
            'to' => '2027-06-30',
            'period' => 'month',
            'compare' => 'game_system',
        ]);

        $this->assertSame('game-table', $filters->subjectType);
        $this->assertSame('user-1', $filters->organizerId);
        $this->assertSame('Dungeons & Dragons', $filters->gameSystem);
        $this->assertSame('2027-01-01', $filters->from?->format('Y-m-d'));
        $this->assertSame('2027-06-30', $filters->to?->format('Y-m-d'));
        $this->assertSame(TrendPeriod::Month, $filters->period);
        $this->assertSame(TrendComparison::GameSystem, $filters->compareBy);
    }

    public function test_invalid_values_fall_back_to_defaults(): void
    {
        $filters = TrendFilters::fromInput([
            'type' => ['game-table'],
            'organizer' => '  ',
            'system' => 42,
            'from' => '31/12/2027',
            'to' => '2027-02-30',
            'period' => 'week',
            'compare' => 'x',
        ]);

        $this->assertEquals(new TrendFilters, $filters);
        $this->assertSame(TrendPeriod::Quarter, $filters->period);
        $this->assertSame(TrendComparison::None, $filters->compareBy);
    }

    public function test_reversed_range_is_dropped(): void
    {
        $filters = TrendFilters::fromInput(['from' => '2027-06-30', 'to' => '2027-01-01']);

        $this->assertNull($filters->from);
        $this->assertNull($filters->to);
    }

    public function test_the_site_cannot_filter_or_compare_by_organizer(): void
    {
        $filters = TrendFilters::fromInput(['organizer' => 'user-2', 'compare' => 'organizer', 'system' => 'Rolemaster'])
            ->withoutOrganizer();

        $this->assertNull($filters->organizerId);
        $this->assertSame(TrendComparison::None, $filters->compareBy);
        $this->assertSame('Rolemaster', $filters->gameSystem);
    }

    public function test_period_keys(): void
    {
        $day = new DateTimeImmutable('2027-02-14');

        $this->assertSame('2027-02', TrendPeriod::Month->keyFor($day));
        $this->assertSame('2027-Q1', TrendPeriod::Quarter->keyFor($day));
        $this->assertSame('2027', TrendPeriod::Year->keyFor($day));
    }

    public function test_between_fills_the_gaps(): void
    {
        $this->assertSame(['2026-Q4', '2027-Q1', '2027-Q2'], TrendPeriod::Quarter->between('2026-Q4', '2027-Q2'));
        $this->assertSame(['2026-11', '2026-12', '2027-01'], TrendPeriod::Month->between('2026-11', '2027-01'));
        $this->assertSame(['2025', '2026', '2027'], TrendPeriod::Year->between('2025', '2027'));
        $this->assertSame(['2027-Q1'], TrendPeriod::Quarter->between('2027-Q1', '2027-Q1'));
    }

    public function test_periods_use_the_display_time_zone(): void
    {
        $instant = new DateTimeImmutable('2027-03-31T23:30:00Z');

        $this->assertSame('2027-Q1', TrendPeriod::Quarter->keyFor($instant));
        $this->assertSame('2027-Q2', TrendPeriod::Quarter->keyFor($instant->setTimezone(new DateTimeZone('Europe/Madrid'))));
    }
}
