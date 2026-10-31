<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Enums;

/**
 * What sending a subject's survey did: it went out, or it waits for a moderator.
 */
enum SendOutcome: string
{
    case Opened = 'opened';
    case SentForReview = 'sent_for_review';
}
