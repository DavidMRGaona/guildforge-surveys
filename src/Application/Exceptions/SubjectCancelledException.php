<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Exceptions;

use DomainException;

/**
 * The subject was cancelled: nobody is left to ask, so it takes no new surveys.
 */
final class SubjectCancelledException extends DomainException
{
    public static function for(string $sourceType, string $subjectId): self
    {
        return new self("The subject {$sourceType}:{$subjectId} was cancelled.");
    }
}
