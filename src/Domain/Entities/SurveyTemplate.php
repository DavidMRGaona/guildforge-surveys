<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Entities;

use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;

/**
 * Reusable content. Its questions carry stable keys that every survey made from it keeps.
 * Availability surveys never become one (SurveyKind::allowsTemplates()).
 */
final class SurveyTemplate
{
    /**
     * @throws InvalidSurveyDefinitionException for a kind that never becomes a template
     */
    public function __construct(
        private readonly SurveyTemplateId $id,
        private string $name,
        private ?string $description,
        private readonly SurveyKind $kind,
        private SurveyDefinition $definition,
        private readonly ?string $createdBy = null,
        private TemplateUses $uses = new TemplateUses([]),
    ) {
        if (! $kind->allowsTemplates()) {
            throw InvalidSurveyDefinitionException::withViolations([new DefinitionViolation('kind', 'kind_without_templates')]);
        }
    }

    /**
     * Templates have no responses, but a keyed question still never changes type.
     *
     * @throws InvalidSurveyDefinitionException
     */
    public function replaceDefinition(SurveyDefinition $definition, SurveyDefinitionValidator $validator): void
    {
        $validator->assertValid($definition, $this->kind);
        $validator->assertChangeAllowed($this->definition, $definition, hasResponses: false);

        $this->definition = $definition;
    }

    public function rename(string $name, ?string $description): void
    {
        $this->name = $name;
        $this->description = $description;
    }

    public function changeUses(TemplateUses $uses): void
    {
        $this->uses = $uses;
    }

    public function id(): SurveyTemplateId
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function kind(): SurveyKind
    {
        return $this->kind;
    }

    public function definition(): SurveyDefinition
    {
        return $this->definition;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }

    public function uses(): TemplateUses
    {
        return $this->uses;
    }
}
