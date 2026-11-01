<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Domain\Entities\Survey;

/**
 * Mails the summary of a survey's results when it closes, if the survey asked for it.
 */
interface ClosingSummaryNotifierInterface
{
    public function surveyClosed(Survey $survey): void;
}
