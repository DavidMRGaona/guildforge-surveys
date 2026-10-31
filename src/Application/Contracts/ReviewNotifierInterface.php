<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Domain\Entities\Survey;

/**
 * Mails about the review of surveys changed outside the panel.
 */
interface ReviewNotifierInterface
{
    /**
     * A survey waits for review: tell whoever may approve it in the panel.
     */
    public function submittedForReview(Survey $survey): void;

    /**
     * A moderator approved it: tell whoever sent it.
     */
    public function approved(Survey $survey): void;

    /**
     * A moderator rejected it: tell whoever sent it, with the reason.
     */
    public function rejected(Survey $survey): void;
}
