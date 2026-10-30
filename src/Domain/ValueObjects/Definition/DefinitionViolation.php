<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

/**
 * A broken rule in a survey definition: where (a dot path into the definition array,
 * e.g. "sections.0.questions.2.options") and which translatable message describes it.
 */
final readonly class DefinitionViolation
{
    public function __construct(
        public string $path,
        public string $messageKey,
    ) {}
}
