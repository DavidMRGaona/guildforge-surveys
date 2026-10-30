<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;

/**
 * What is wrong with each question of a submission. Never carries the answers themselves.
 */
final class InvalidSubmissionException extends DomainException
{
    /**
     * @param  array<string, string>  $errors  question id => message key
     */
    private function __construct(public readonly array $errors)
    {
        parent::__construct('The submission does not fit the survey: '.implode(', ', array_keys($errors)).'.');
    }

    /**
     * @param  non-empty-array<string, string>  $errors
     */
    public static function withErrors(array $errors): self
    {
        return new self($errors);
    }
}
