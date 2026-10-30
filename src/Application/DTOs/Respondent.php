<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

/**
 * Who is answering: an account, a guest with their link, or anyone holding the public link.
 */
final readonly class Respondent
{
    public const string ACCOUNT = 'account';

    public const string GUEST = 'guest';

    public const string PUBLIC_LINK = 'public_link';

    private function __construct(
        public string $kind,
        public ?string $userId = null,
        public ?string $token = null,
    ) {}

    public static function account(string $userId): self
    {
        return new self(self::ACCOUNT, userId: $userId);
    }

    public static function guest(string $token): self
    {
        return new self(self::GUEST, token: $token);
    }

    public static function publicLink(string $token): self
    {
        return new self(self::PUBLIC_LINK, token: $token);
    }
}
