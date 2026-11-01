<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects;

/**
 * What goes out when a subject ends, as chosen for that subject instead of its type's template:
 * another template, or no survey at all.
 */
final readonly class SubjectEndChoice
{
    private function __construct(
        public string $sourceType,
        public string $subjectId,
        public ?SurveyTemplateId $templateId,
    ) {}

    public static function template(string $sourceType, string $subjectId, SurveyTemplateId $templateId): self
    {
        return new self($sourceType, $subjectId, $templateId);
    }

    public static function none(string $sourceType, string $subjectId): self
    {
        return new self($sourceType, $subjectId, null);
    }

    public function isNone(): bool
    {
        return ! $this->templateId instanceof SurveyTemplateId;
    }
}
