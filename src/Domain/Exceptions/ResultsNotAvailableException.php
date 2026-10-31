<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;

final class ResultsNotAvailableException extends DomainException
{
    public static function for(string $surveyId, string $reason): self
    {
        return new self("The results of survey {$surveyId} are not available yet ({$reason}).");
    }
}
