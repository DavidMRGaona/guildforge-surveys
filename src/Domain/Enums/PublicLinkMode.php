<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

/**
 * How the public link lets people in. Open: anyone with the link answers, as often as they
 * like. Verified email: the link asks for an address and mails it a single-use link, so each
 * address answers once.
 */
enum PublicLinkMode: string
{
    case Open = 'open';
    case VerifiedEmail = 'verified_email';
}
