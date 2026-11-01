<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Modules\Surveys\Application\Services\AvailabilitySlotFormatter;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Slots are typed and read in the display zone (Madrid here) and stored as UTC instants.
 */
final class AvailabilitySlotFormatterTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_a_whole_day_stays_a_calendar_date(): void
    {
        $this->assertEquals(new Slot('d-1', date: '2026-12-05'), $this->formatter()->fromWallTime('d-1', '2026-12-05', null, null));
    }

    public function test_times_typed_in_the_display_zone_are_stored_in_utc_and_read_back(): void
    {
        $formatter = $this->formatter();

        $winter = $formatter->fromWallTime('d-2', '2026-12-11', '17:00', '21:00');
        $summer = $formatter->fromWallTime('d-3', '2027-07-03', '10:30', null);

        $this->assertEquals(new Slot('d-2', startsAt: '2026-12-11T16:00:00+00:00', endsAt: '2026-12-11T20:00:00+00:00'), $winter);
        $this->assertEquals(new Slot('d-3', startsAt: '2027-07-03T08:30:00+00:00'), $summer);
        $this->assertSame(['2026-12-11', '17:00', '21:00'], [$formatter->day($winter), $formatter->start($winter), $formatter->end($winter)]);
        $this->assertSame(['2027-07-03', '10:30', null], [$formatter->day($summer), $formatter->start($summer), $formatter->end($summer)]);
    }

    public function test_an_end_before_the_start_is_on_the_next_day(): void
    {
        $slot = $this->formatter()->fromWallTime('d-4', '2026-12-31', '22:00', '02:00');

        $this->assertEquals(new Slot('d-4', startsAt: '2026-12-31T21:00:00+00:00', endsAt: '2027-01-01T01:00:00+00:00'), $slot);
        $this->assertSame('jueves, 31 de diciembre de 2026, de 22:00 a 02:00', $this->formatter()->label($slot));
    }

    public function test_a_late_evening_in_madrid_keeps_its_day(): void
    {
        $slot = new Slot('d-5', startsAt: '2026-12-11T23:30:00+00:00');

        $this->assertSame(['2026-12-12', '00:30'], [$this->formatter()->day($slot), $this->formatter()->start($slot)]);
    }

    private function formatter(): AvailabilitySlotFormatter
    {
        return $this->app->make(AvailabilitySlotFormatter::class);
    }
}
