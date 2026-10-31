<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

/**
 * Who gets a link after asking for one on the verified public link: an account of the
 * audience (its survey page), or a guest or an address with its single-use link token.
 */
final readonly class AccessLinkRecipient
{
    private function __construct(
        public ?string $userId = null,
        public ?string $email = null,
        public ?string $token = null,
    ) {}

    public static function account(string $userId): self
    {
        return new self(userId: $userId);
    }

    public static function withLink(string $email, string $token): self
    {
        return new self(email: $email, token: $token);
    }
}
