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

    public static function cannotRenewPublicLink(string $surveyId, string $status): self
    {
        return new self("Survey {$surveyId} cannot renew its public link from status '{$status}' or without one.");
    }

    public static function alreadyClosed(string $surveyId): self
    {
        return new self("Survey {$surveyId} is closed.");
    }

    public static function durationFixed(string $surveyId): self
    {
        return new self("Survey {$surveyId} has opened: its duration is fixed.");
    }

    public static function invalidDuration(string $surveyId): self
    {
        return new self("Survey {$surveyId} must stay open at least one day.");
    }

    public static function cannotSubmitForReview(string $surveyId, string $status): self
    {
        return new self("Survey {$surveyId} cannot go to review from status '{$status}'.");
    }

    public static function notPendingReview(string $surveyId, string $status): self
    {
        return new self("Survey {$surveyId} is not pending review (status '{$status}').");
    }

    public static function rejectionNeedsReason(string $surveyId): self
    {
        return new self("Rejecting survey {$surveyId} needs a reason.");
    }

    public static function cannotSchedule(string $surveyId, string $status): self
    {
        return new self("Survey {$surveyId} cannot be scheduled from status '{$status}'.");
    }

    public static function cannotDismiss(string $surveyId, string $status): self
    {
        return new self("Survey {$surveyId} cannot be dismissed from status '{$status}'.");
    }

    public static function notAwaitingSubjectEnd(string $surveyId): self
    {
        return new self("Survey {$surveyId} is not waiting for its subject to end.");
    }

    public static function awaitingSubjectEnd(string $surveyId): self
    {
        return new self("Survey {$surveyId} waits for its subject to end.");
    }

    public static function invalidSendDelay(string $surveyId): self
    {
        return new self("Survey {$surveyId} needs a send delay of zero hours or more.");
    }

    public static function subjectFixed(string $surveyId): self
    {
        return new self("Survey {$surveyId} already opened: its subject snapshot is fixed.");
    }

    public static function otherSubject(string $surveyId): self
    {
        return new self("Survey {$surveyId} is about another subject.");
    }
}
