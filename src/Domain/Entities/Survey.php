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
        private string $title,
        private ?string $description,
        private SurveyDefinition $definition,
        private bool $anonymous,
        private ResultsSettings $results,
        private AudienceType $audienceType,
        private array $audienceRoles = [],
        private bool $publicLinkEnabled = false,
        private readonly ?SurveyTemplateId $templateId = null,
        private readonly ?SubjectSnapshot $subject = null,
        private SurveyStatus $status = SurveyStatus::Draft,
        private ?DateTimeImmutable $opensAt = null,
        private ?DateTimeImmutable $closesAt = null,
        private ?DateTimeImmutable $closedAt = null,
        private ?int $reminderHoursBefore = null,
        private readonly ?string $createdBy = null,
        private int $audienceSize = 0,
        private ?string $publicToken = null,
    ) {}

    /**
     * @param  int  $audienceSize  people the survey goes to, fixed now: the base of the response rate
     * @param  string|null  $publicToken  the open link's token, when the survey has one
     */
    public function open(DateTimeImmutable $now, int $audienceSize = 0, ?string $publicToken = null): void
    {
        if ($this->status !== SurveyStatus::Draft) {
            throw InvalidSurveyStateException::cannotOpen($this->id->value, $this->status->value);
        }

        if ($this->closesAt instanceof DateTimeImmutable && $this->closesAt <= $now) {
            throw InvalidSurveyStateException::closingDateInThePast($this->id->value);
        }

        $this->status = SurveyStatus::Open;
        $this->opensAt = $now;
        $this->audienceSize = $audienceSize;
        $this->publicToken = $this->publicLinkEnabled ? $publicToken : null;
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
     * Closed, or past its closing date while the scheduled close has not run yet.
     */
    public function hasEnded(DateTimeImmutable $now): bool
    {
        return $this->status === SurveyStatus::Closed
            || ($this->status === SurveyStatus::Open && $this->closesAt instanceof DateTimeImmutable && $this->closesAt <= $now);
    }

    /**
     * Title and description are content too: once there are responses they stay as answered.
     */
    public function editDetails(string $title, ?string $description, bool $hasResponses): void
    {
        if ($title === $this->title && $description === $this->description) {
            return;
        }

        if ($hasResponses) {
            throw InvalidSurveyStateException::lockedByResponses($this->id->value);
        }

        $this->title = $title;
        $this->description = $description;
    }

    /**
     * Who the survey goes to is fixed when it opens.
     *
     * @param  list<string>  $roles  only kept for AudienceType::Roles
     */
    public function changeAudience(AudienceType $type, array $roles, bool $publicLinkEnabled): void
    {
        $roles = $type === AudienceType::Roles ? $roles : [];
        if ($type === $this->audienceType && $roles === $this->audienceRoles && $publicLinkEnabled === $this->publicLinkEnabled) {
            return;
        }

        if ($this->status !== SurveyStatus::Draft) {
            throw InvalidSurveyStateException::audienceFixed($this->id->value);
        }

        $this->audienceType = $type;
        $this->audienceRoles = $roles;
        $this->publicLinkEnabled = $publicLinkEnabled;
    }

    /**
     * The closing date and the reminder may move until the survey closes, never into the past
     * of an open survey.
     */
    public function reschedule(?DateTimeImmutable $closesAt, ?int $reminderHoursBefore, DateTimeImmutable $now): void
    {
        if ($closesAt == $this->closesAt && $reminderHoursBefore === $this->reminderHoursBefore) {
            return;
        }

        if ($this->status === SurveyStatus::Closed) {
            throw InvalidSurveyStateException::alreadyClosed($this->id->value);
        }

        if ($this->status === SurveyStatus::Open && $closesAt instanceof DateTimeImmutable && $closesAt <= $now) {
            throw InvalidSurveyStateException::closingDateInThePast($this->id->value);
        }

        $this->closesAt = $closesAt;
        $this->reminderHoursBefore = $reminderHoursBefore;
    }

    public function changeResults(ResultsSettings $results): void
    {
        $this->results = $results;
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

    public function audienceSize(): int
    {
        return $this->audienceSize;
    }

    public function publicToken(): ?string
    {
        return $this->publicToken;
    }
}
