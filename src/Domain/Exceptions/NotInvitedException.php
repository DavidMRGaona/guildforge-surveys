<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;

final class NotInvitedException extends DomainException
{
    public static function for(string $surveyId): self
    {
        return new self("This person may not answer survey {$surveyId}.");
    }
}
