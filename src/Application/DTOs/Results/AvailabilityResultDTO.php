<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

/**
 * The results of an availability question: every slot in the survey's order. The best are those
 * with the most "yes" and, among them, the most "if needed"; all of them on a tie, none while
 * nobody can make any.
 */
final readonly class AvailabilityResultDTO
{
    /**
     * @param  list<AvailabilitySlotResultDTO>  $slots
     */
    public function __construct(public array $slots) {}

    /**
     * @return list<AvailabilitySlotResultDTO>
     */
    public function best(): array
    {
        return array_values(array_filter($this->slots, static fn (AvailabilitySlotResultDTO $slot): bool => $slot->best));
    }
}
