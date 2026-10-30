<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Exceptions;

use DomainException;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;

final class InvalidSurveyDefinitionException extends DomainException
{
    /**
     * @param  list<DefinitionViolation>  $violations
     */
    private function __construct(public readonly array $violations)
    {
        $first = $violations[0];

        parent::__construct("Invalid survey definition at '{$first->path}': {$first->messageKey}.");
    }

    /**
     * @param  non-empty-list<DefinitionViolation>  $violations
     */
    public static function withViolations(array $violations): self
    {
        return new self($violations);
    }
}
