<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Templates;

use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;

/**
 * A template the module ships. The catalog key finds it again once seeded, however the association
 * renamed it; the subject type, when set, is the kind of subject it is the default template for,
 * and its uses say what it is meant for.
 */
final readonly class RecommendedTemplate
{
    public function __construct(
        public string $catalogKey,
        public ?string $subjectType,
        public TemplateUses $uses,
        public string $name,
        public string $description,
        public SurveyDefinition $definition,
    ) {}
}
