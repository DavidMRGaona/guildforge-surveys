<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;

final class InvalidSurveyStateException extends DomainException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function cannotOpen(string $surveyId, string $status): self
    {
        return new self("Survey {$surveyId} cannot open from status '{$status}'.");
    }

    public static function closingDateInThePast(string $surveyId): self
    {
        return new self("Survey {$surveyId} cannot open: its closing date has already passed.");
    }

    public static function cannotClose(string $surveyId, string $status): self
    {
        return new self("Survey {$surveyId} cannot close from status '{$status}'.");
    }

    public static function anonymityIsPermanent(string $surveyId): self
    {
        return new self("Survey {$surveyId} is anonymous and can never become named.");
    }

    public static function anonymityLockedByResponses(string $surveyId): self
    {
        return new self("Survey {$surveyId} already has responses: its anonymity can no longer change.");
    }

    public static function lockedByResponses(string $surveyId): self
    {
        return new self("Survey {$surveyId} already has responses: its content can no longer change.");
    }

    public static function audienceFixed(string $surveyId): self
    {
        return new self("Survey {$surveyId} is no longer a draft: its audience is fixed.");
    }

    public static function alreadyClosed(string $surveyId): self
    {
        return new self("Survey {$surveyId} is closed.");
    }
}
