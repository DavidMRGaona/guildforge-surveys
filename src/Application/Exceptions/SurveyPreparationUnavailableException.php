<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Exceptions;

use DomainException;

/**
 * A subject's end survey cannot be prepared: its type has no template, or the subject was
 * cancelled and will never end. The reason names the message shown to the organizer.
 */
final class SurveyPreparationUnavailableException extends DomainException
{
    /**
     * @param  'no_template'|'cancelled'  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("The subject's end survey cannot be prepared ({$reason}).");
    }
}
