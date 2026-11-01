<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Domain\Entities\Survey;

interface SurveyAnnouncerInterface
{
    /**
     * Announces a general survey that has just opened to whoever posts on channels, once the
     * opening commits. Without a module listening, nothing happens.
     */
    public function announceOpened(Survey $survey): void;
}
