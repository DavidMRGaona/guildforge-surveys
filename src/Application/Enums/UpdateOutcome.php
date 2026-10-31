<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Enums;

/**
 * What saving an organizer's changes did: saved, or sent an open survey back to review.
 */
enum UpdateOutcome: string
{
    case Saved = 'saved';
    case SentForReview = 'sent_for_review';
}
