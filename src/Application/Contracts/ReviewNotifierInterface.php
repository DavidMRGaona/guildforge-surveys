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
}
