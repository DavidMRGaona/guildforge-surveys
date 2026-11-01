<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;

/**
 * Reads the slots of an availability question as people see them: day and times in the display
 * zone, and a label in the current language ("sábado, 5 de diciembre de 2026, de 17:00 a 21:00").
 * The way back, from what an editor typed in the display zone, is fromWallTime().
 */
final readonly class AvailabilitySlotFormatter
{
    public function __construct(private TimezoneServiceInterface $timezone) {}

    /**
     * The slot's day in the display zone, Y-m-d.
     */
    public function day(Slot $slot): string
    {
        $start = $this->instant($slot->startsAt);

        return $start instanceof DateTimeImmutable ? $this->timezone->format($start, 'Y-m-d') : (string) $slot->date;
    }

    /**
     * H:i in the display zone, or null for a whole day.
     */
    public function start(Slot $slot): ?string
    {
        $start = $this->instant($slot->startsAt);

        return $start instanceof DateTimeImmutable ? $this->timezone->format($start, 'H:i') : null;
    }

    public function end(Slot $slot): ?string
    {
        $end = $this->instant($slot->endsAt);

        return $end instanceof DateTimeImmutable ? $this->timezone->format($end, 'H:i') : null;
    }

    public function label(Slot $slot): string
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $this->day($slot));
        $localized = $day instanceof DateTimeImmutable ? CarbonImmutable::instance($day)->locale(app()->getLocale()) : null;
        $dayLabel = $localized instanceof CarbonImmutable ? $localized->isoFormat('dddd, LL') : $this->day($slot);
        $start = $this->start($slot);
        $end = $this->end($slot);

        return (string) match (true) {
            $start === null => $dayLabel,
            $end === null => __('surveys::messages.availability.slot_at', ['day' => $dayLabel, 'start' => $start]),
            default => __('surveys::messages.availability.slot_from_to', ['day' => $dayLabel, 'start' => $start, 'end' => $end]),
        };
    }

    /**
     * The slot an editor typed in the display zone: a whole day without $start, else the start
     * (and the end, if any) as UTC instants. A $end at or before $start falls on the next day.
     */
    public function fromWallTime(string $id, string $day, ?string $start, ?string $end): Slot
    {
        if ($start === null) {
            return new Slot($id, date: $day);
        }

        $startsAt = $this->timezone->fromDisplayWallTime("{$day} {$start}");
        $endsAt = $end === null ? null : $this->timezone->fromDisplayWallTime("{$day} {$end}");
        if ($endsAt instanceof DateTimeImmutable && $endsAt <= $startsAt) {
            $endsAt = $this->timezone->fromDisplayWallTime(CarbonImmutable::parse($day)->addDay()->format('Y-m-d')." {$end}");
        }

        return new Slot($id, startsAt: $this->utc($startsAt), endsAt: $endsAt instanceof DateTimeImmutable ? $this->utc($endsAt) : null);
    }

    private function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    private function instant(?string $iso): ?DateTimeImmutable
    {
        if ($iso === null) {
            return null;
        }

        $instant = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $iso);

        return $instant === false ? null : $instant;
    }
}
