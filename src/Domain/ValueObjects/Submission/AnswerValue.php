<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Submission;

/**
 * One stored value of an answer. Checkboxes give one value per ticked option, a grid one
 * per row (rowId plus the chosen column as optionId), a ranking one per option (its place as
 * number, 1 the first), and "Other" a value without option holding the typed text. Dates are "Y-m-d" and times "H:i", both in text.
 */
final readonly class AnswerValue
{
    public function __construct(
        public string $questionId,
        public ?string $optionId = null,
        public ?string $rowId = null,
        public ?string $text = null,
        public ?int $number = null,
    ) {}

    public function isOther(): bool
    {
        return $this->optionId === null && $this->rowId === null && $this->number === null;
    }
}
