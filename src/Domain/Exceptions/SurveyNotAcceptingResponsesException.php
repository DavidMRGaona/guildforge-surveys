<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;

final class SurveyNotAcceptingResponsesException extends DomainException
{
    public static function for(string $surveyId): self
    {
        return new self("Survey {$surveyId} is not accepting responses.");
    }
}
