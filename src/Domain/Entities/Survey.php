<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Entities;

use DateTimeImmutable;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;

final class Survey
{
    /**
     * @param  list<string>  $audienceRoles  role names, for AudienceType::Roles
     */
    public function __construct(
        private readonly SurveyId $id,
        private readonly SurveyKind $kind,
        private readonly string $title,
        private readonly ?string $description,
        private SurveyDefinition $definition,
        private bool $anonymous,
        private readonly ResultsSettings $results,
        private readonly AudienceType $audienceType,
        private readonly array $audienceRoles = [],
        private readonly bool $publicLinkEnabled = false,
        private readonly ?SurveyTemplateId $templateId = null,
        private readonly ?SubjectSnapshot $subject = null,
        private SurveyStatus $status = SurveyStatus::Draft,
        private ?DateTimeImmutable $opensAt = null,
        private readonly ?DateTimeImmutable $closesAt = null,
        private ?DateTimeImmutable $closedAt = null,
        private readonly ?int $reminderHoursBefore = null,
        private readonly ?string $createdBy = null,
    ) {}

    public function open(DateTimeImmutable $now): void
    {
        if ($this->status !== SurveyStatus::Draft) {
            throw InvalidSurveyStateException::cannotOpen($this->id->value, $this->status->value);
        }

        if ($this->closesAt instanceof DateTimeImmutable && $this->closesAt <= $now) {
            throw InvalidSurveyStateException::closingDateInThePast($this->id->value);
        }

        $this->status = SurveyStatus::Open;
        $this->opensAt = $now;
    }

    public function close(DateTimeImmutable $now): void
    {
        if ($this->status !== SurveyStatus::Open) {
            throw InvalidSurveyStateException::cannotClose($this->id->value, $this->status->value);
        }

        $this->status = SurveyStatus::Closed;
        $this->closedAt = $now;
    }

    /**
     * True only while open and before the closing date: the scheduled close may run late,
     * so the date decides, not the status alone.
     */
    public function isAcceptingResponses(DateTimeImmutable $now): bool
    {
        return $this->status === SurveyStatus::Open
            && (! $this->closesAt instanceof DateTimeImmutable || $now < $this->closesAt);
    }

    /**
     * @throws InvalidSurveyDefinitionException
     */
    public function replaceDefinition(SurveyDefinition $definition, bool $hasResponses, SurveyDefinitionValidator $validator): void
    {
        $validator->assertValid($definition, $this->kind);
        $validator->assertChangeAllowed($this->definition, $definition, $hasResponses);

        $this->definition = $definition;
    }

    /**
     * An anonymous survey stays anonymous; a named one may become anonymous until someone answers.
     */
    public function changeAnonymity(bool $anonymous, bool $hasResponses): void
    {
        if ($anonymous === $this->anonymous) {
            return;
        }

        if ($this->anonymous) {
            throw InvalidSurveyStateException::anonymityIsPermanent($this->id->value);
        }

        if ($hasResponses) {
            throw InvalidSurveyStateException::anonymityLockedByResponses($this->id->value);
        }

        $this->anonymous = true;
    }

    public function id(): SurveyId
    {
        return $this->id;
    }

    public function kind(): SurveyKind
    {
        return $this->kind;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function definition(): SurveyDefinition
    {
        return $this->definition;
    }

    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    public function results(): ResultsSettings
    {
        return $this->results;
    }

    public function audienceType(): AudienceType
    {
        return $this->audienceType;
    }

    /**
     * @return list<string>
     */
    public function audienceRoles(): array
    {
        return $this->audienceRoles;
    }

    public function isPublicLinkEnabled(): bool
    {
        return $this->publicLinkEnabled;
    }

    public function templateId(): ?SurveyTemplateId
    {
        return $this->templateId;
    }

    public function subject(): ?SubjectSnapshot
    {
        return $this->subject;
    }

    public function status(): SurveyStatus
    {
        return $this->status;
    }

    public function opensAt(): ?DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function closesAt(): ?DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function reminderHoursBefore(): ?int
    {
        return $this->reminderHoursBefore;
    }

    public function createdBy(): ?string
    {
        return $this->createdBy;
    }
}
