<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;

final class AlreadyAnsweredException extends DomainException
{
    public static function for(string $surveyId): self
    {
        return new self("Survey {$surveyId} has already been answered by this person.");
    }
}
