<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

/**
 * Where choosing an option sends the respondent: a later section's id or Section::SUBMIT.
 * The option id is Submission::OTHER for "Other".
 */
final readonly class OptionJump
{
    public function __construct(
        public string $optionId,
        public string $next,
    ) {}

    /**
     * @return array{option_id: string, next: string}
     */
    public function toArray(): array
    {
        return ['option_id' => $this->optionId, 'next' => $this->next];
    }
}
