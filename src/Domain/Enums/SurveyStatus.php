<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

enum SurveyStatus: string
{
    case Draft = 'draft';
    /** Changed outside the panel and waiting for a moderator: neither sent nor answerable. */
    case PendingReview = 'pending_review';
    case Open = 'open';
    case Closed = 'closed';
}
