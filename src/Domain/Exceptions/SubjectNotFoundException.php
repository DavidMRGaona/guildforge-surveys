<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;

final class SubjectNotFoundException extends DomainException
{
    public static function for(string $sourceType, string $subjectId): self
    {
        return new self("No survey subject '{$subjectId}' of type '{$sourceType}' is offered.");
    }
}
