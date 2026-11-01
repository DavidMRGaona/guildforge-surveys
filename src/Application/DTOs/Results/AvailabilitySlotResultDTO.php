<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

/**
 * One proposed slot with how many can make it, could if needed and cannot, read in the
 * display zone. The names only come on named surveys.
 */
final readonly class AvailabilitySlotResultDTO
{
    /**
     * @param  string  $day  the slot's day in the display zone, Y-m-d
     * @param  ?string  $start  H:i in the display zone, null for a whole day
     * @param  ?string  $end  H:i in the display zone, when the slot has an end
     * @param  bool  $best  among the slots most people can make (see AvailabilityResultDTO)
     * @param  list<string>  $yesNames
     * @param  list<string>  $ifNeededNames
     * @param  list<string>  $noNames
     */
    public function __construct(
        public string $slotId,
        public string $label,
        public string $day,
        public ?string $start,
        public ?string $end,
        public int $yes,
        public int $ifNeeded,
        public int $no,
        public bool $best,
        public array $yesNames = [],
        public array $ifNeededNames = [],
        public array $noNames = [],
    ) {}
}
