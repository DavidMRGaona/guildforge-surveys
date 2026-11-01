<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Services\TimezoneServiceInterface;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;

/**
 * Reads the slots of an availability question as people see them: day and times in the display
 * zone, and a label in the current language ("sábado, 5 de diciembre de 2026, de 17:00 a 21:00").
 * The way back, from what an editor typed in the display zone, is fromWallTime().
 *
 * The browser gets a definition with each slot also as it is shown (presentDefinition()), and the
 * web editor sends slots back as typed: {id, day, start, end} (readEditedDefinition()).
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
        $localized = $day instanceof DateTimeImmutable && $day->format('Y-m-d') === $this->day($slot) ? CarbonImmutable::instance($day)->locale(app()->getLocale()) : null;
        if (! $localized instanceof CarbonImmutable) {
            return $this->day($slot);
        }
        $dayLabel = $localized->isoFormat('dddd, LL');
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
     * What cannot be read is kept as typed, so SurveyDefinitionValidator says where it is wrong.
     */
    public function fromWallTime(string $id, string $day, ?string $start, ?string $end): Slot
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if ($start === null || $date === false || $date->format('Y-m-d') !== $day) {
            return new Slot($id, date: $day);
        }

        $startsAt = $this->wallTime("{$day} {$start}");
        if (! $startsAt instanceof DateTimeImmutable) {
            return new Slot($id, startsAt: $start);
        }

        $endsAt = $end === null ? null : $this->wallTime("{$day} {$end}");
        if ($endsAt instanceof DateTimeImmutable && $endsAt <= $startsAt) {
            $endsAt = $this->wallTime($date->modify('+1 day')->format('Y-m-d')." {$end}");
        }

        return new Slot(
            $id,
            startsAt: $this->utc($startsAt),
            endsAt: $endsAt instanceof DateTimeImmutable ? $this->utc($endsAt) : $end,
        );
    }

    /**
     * The definition as stored, each slot also as shown: day, start, end and label.
     *
     * @return array<string, mixed>
     */
    public function presentDefinition(SurveyDefinition $definition): array
    {
        $data = $definition->toArray();
        foreach ($definition->sections as $s => $section) {
            foreach ($section->questions as $q => $question) {
                $data['sections'][$s]['questions'][$q]['slots'] = array_map(fn (Slot $slot): array => [
                    ...$slot->toArray(),
                    'day' => $this->day($slot),
                    'start' => $this->start($slot),
                    'end' => $this->end($slot),
                    'label' => $this->label($slot),
                ], $question->slots);
            }
        }

        return $data;
    }

    /**
     * A definition from the web editor, whose availability slots come as typed ({id, day,
     * start, end}), with those slots as stored. Anything else is left for SurveyDefinition.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function readEditedDefinition(array $data): array
    {
        foreach (is_array($data['sections'] ?? null) ? $data['sections'] : [] as $s => $section) {
            foreach (is_array($section['questions'] ?? null) ? $section['questions'] : [] as $q => $question) {
                if (! is_array($question) || ($question['type'] ?? null) !== QuestionType::Availability->value || ! is_array($question['slots'] ?? null)) {
                    continue;
                }

                $data['sections'][$s]['questions'][$q]['slots'] = array_map(
                    fn (mixed $slot): mixed => is_array($slot) && array_key_exists('day', $slot) ? $this->fromWallTime(
                        id: is_string($slot['id'] ?? null) ? $slot['id'] : '',
                        day: is_string($slot['day']) ? $slot['day'] : '',
                        start: is_string($slot['start'] ?? null) && $slot['start'] !== '' ? $slot['start'] : null,
                        end: is_string($slot['end'] ?? null) && $slot['end'] !== '' ? $slot['end'] : null,
                    )->toArray() : $slot,
                    $question['slots'],
                );
            }
        }

        return $data;
    }

    private function wallTime(string $wallTime): ?DateTimeImmutable
    {
        try {
            return $this->timezone->fromDisplayWallTime($wallTime);
        } catch (InvalidArgumentException) {
            return null;
        }
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
