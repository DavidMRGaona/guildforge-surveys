<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Templates;

use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;

/**
 * A template the module ships. The catalog key finds it again once seeded, however the association
 * renamed it; the subject type, when set, is the kind of subject it is the default template for.
 */
final readonly class RecommendedTemplate
{
    public function __construct(
        public string $catalogKey,
        public ?string $subjectType,
        public string $name,
        public string $description,
        public SurveyDefinition $definition,
    ) {}
}
