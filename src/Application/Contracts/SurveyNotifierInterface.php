<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Domain\ValueObjects\SurveyId;

interface SurveyNotifierInterface
{
    /**
     * Mails every recipient not mailed yet. Running it again mails nobody twice.
     */
    public function notifyOpened(SurveyId $surveyId): void;

    /**
     * Reminds the recipients who have not answered. Returns how many were reminded.
     */
    public function remindPending(SurveyId $surveyId): int;
}
