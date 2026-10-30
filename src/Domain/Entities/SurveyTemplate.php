<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Entities;

use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

/**
 * Reusable content. Its questions carry stable keys that every survey made from it keeps.
 */
final class SurveyTemplate
{
    public function __construct(
        private readonly SurveyTemplateId $id,
        private readonly string $name,
        private readonly ?string $description,
        private readonly SurveyKind $kind,
        private SurveyDefinition $definition,
        private readonly ?string $createdBy = null,
    ) {}

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
}
