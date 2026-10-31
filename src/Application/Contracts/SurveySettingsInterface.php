<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

interface SurveySettingsInterface
{
    /**
     * Responses an anonymous survey needs before its results, free text or export show; 0 means none.
     */
    public function minResponses(): int;

    public function defaultCloseDays(): int;

    public function anonymousByDefault(): bool;
}
