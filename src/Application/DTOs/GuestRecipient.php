<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

/**
 * Whoever holds a single-use link: a guest of the audience, with the name a named survey
 * keeps, or an address that asked for it on the public link, with no name.
 */
final readonly class GuestRecipient
{
    public function __construct(
        public ?string $name,
        public bool $fromPublicLink,
    ) {}
}
