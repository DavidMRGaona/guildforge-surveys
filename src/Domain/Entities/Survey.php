<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Entities;

use DateTimeImmutable;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
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
        private ?SubjectSnapshot $subject = null,
        private SurveyStatus $status = SurveyStatus::Draft,
        private ?DateTimeImmutable $opensAt = null,
        private ?DateTimeImmutable $closesAt = null,
        private ?DateTimeImmutable $closedAt = null,
        private ?int $reminderHoursBefore = null,
        private readonly ?string $createdBy = null,
        private int $audienceSize = 0,
        private ?string $publicToken = null,
        private ?int $durationDays = null,
        private bool $unreviewedChanges = false,
        private ?string $lastChangedBy = null,
        private ?string $rejectionReason = null,
        private ?string $submittedBy = null,
        private ?DateTimeImmutable $submittedForReviewAt = null,
        private ?string $reviewedBy = null,
        private ?DateTimeImmutable $reviewedAt = null,
        private ?DateTimeImmutable $scheduledSendAt = null,
        private ?DateTimeImmutable $dismissedAt = null,
        private ?string $subjectEndKey = null,
        private ?DateTimeImmutable $subjectEndedAt = null,
        private bool $sendOnEnd = false,
        private int $sendDelayHours = 0,
        private PublicLinkMode $publicLinkMode = PublicLinkMode::VerifiedEmail,
        private bool $announceInChannels = false,
    ) {}

    /**
     * A survey with a requested duration closes that many days after it opens. Opening again
     * after a rejection resumes it: its audience, response base and links stay as first fixed.
     *
     * @param  int  $audienceSize  people the survey goes to, fixed now: the base of the response rate
     * @param  string|null  $publicToken  the open link's token, when the survey has one
     */
    public function open(DateTimeImmutable $now, int $audienceSize = 0, ?string $publicToken = null): void
    {
        if ($this->status !== SurveyStatus::Draft) {
            throw InvalidSurveyStateException::cannotOpen($this->id->value, $this->status->value);
        }

        if ($this->durationDays !== null) {
            $this->closesAt = $now->modify("+{$this->durationDays} days");
        }

        if ($this->closesAt instanceof DateTimeImmutable && $this->closesAt <= $now) {
            throw InvalidSurveyStateException::closingDateInThePast($this->id->value);
        }

        $this->status = SurveyStatus::Open;
        $this->unreviewedChanges = false;
        $this->scheduledSendAt = null;

        if (! $this->wasOpened()) {
            $this->opensAt = $now;
            $this->audienceSize = $audienceSize;
            $this->publicToken = $this->publicLinkEnabled ? $publicToken : null;
        }
    }

    public function wasOpened(): bool
    {
        return $this->opensAt instanceof DateTimeImmutable;
    }

    /**
     * Days the survey stays open, counted from when it opens (or is approved): only before it
     * first opens. Null keeps the closing date as set.
     */
    public function changeDuration(?int $days): void
    {
        if ($days === $this->durationDays) {
            return;
        }

        if ($this->wasOpened()) {
            throw InvalidSurveyStateException::durationFixed($this->id->value);
        }

        if ($days !== null && $days < 1) {
            throw InvalidSurveyStateException::invalidDuration($this->id->value);
        }

        $this->durationDays = $days;
    }

    /**
     * Whether a title, description or definition differs from the survey's content. Dates,
     * anonymity and results settings are not content.
     */
    public function contentDiffers(string $title, ?string $description, SurveyDefinition $definition): bool
    {
        return $title !== $this->title
            || $description !== $this->description
            || $definition->toArray() !== $this->definition->toArray();
    }

    /**
     * Content changed outside the panel that no moderator has seen yet.
     */
    public function markUnreviewedChanges(string $userId): void
    {
        $this->unreviewedChanges = true;
        $this->lastChangedBy = $userId;
    }

    /**
     * Holds the survey until a moderator approves it: it neither goes out nor takes responses.
     */
    public function submitForReview(string $userId, DateTimeImmutable $now): void
    {
        if (! in_array($this->status, [SurveyStatus::Draft, SurveyStatus::Open, SurveyStatus::PendingReview], true)) {
            throw InvalidSurveyStateException::cannotSubmitForReview($this->id->value, $this->status->value);
        }

        $this->status = SurveyStatus::PendingReview;
        $this->submittedBy = $userId;
        $this->submittedForReviewAt = $now;
        $this->rejectionReason = null;
        $this->scheduledSendAt = null;
    }

    /**
     * A survey that had opened goes back to taking responses, its duration counted from now; one
     * that never opened returns to a draft, for the caller to open it.
     */
    public function approve(string $moderatorId, DateTimeImmutable $now): void
    {
        $this->assertPendingReview();

        $this->reviewedBy = $moderatorId;
        $this->reviewedAt = $now;
        $this->unreviewedChanges = false;

        if (! $this->wasOpened()) {
            $this->status = SurveyStatus::Draft;

            return;
        }

        if ($this->durationDays !== null) {
            $this->closesAt = $now->modify("+{$this->durationDays} days");
        }
        $this->status = SurveyStatus::Open;
    }

    /**
     * Back to a draft that keeps the reason, so the organizer can fix it and send it again.
     */
    public function reject(string $moderatorId, string $reason, DateTimeImmutable $now): void
    {
        $this->assertPendingReview();

        if (trim($reason) === '') {
            throw InvalidSurveyStateException::rejectionNeedsReason($this->id->value);
        }

        $this->status = SurveyStatus::Draft;
        $this->rejectionReason = $reason;
        $this->reviewedBy = $moderatorId;
        $this->reviewedAt = $now;
    }

    /**
     * When an automatically sent survey goes out: only a draft that never opened.
     */
    public function scheduleSending(DateTimeImmutable $at): void
    {
        if ($this->status !== SurveyStatus::Draft || $this->wasOpened() || $this->dismissedAt instanceof DateTimeImmutable) {
            throw InvalidSurveyStateException::cannotSchedule($this->id->value, $this->status->value);
        }

        $this->scheduledSendAt = $at;
    }

    public function isDueForSending(DateTimeImmutable $now): bool
    {
        return $this->status === SurveyStatus::Draft
            && ! $this->dismissedAt instanceof DateTimeImmutable
            && $this->scheduledSendAt instanceof DateTimeImmutable
            && $this->scheduledSendAt <= $now;
    }

    /**
     * The organizer chose not to send a survey offered to them; it stays in the panel.
     */
    public function dismiss(DateTimeImmutable $now): void
    {
        if ($this->status !== SurveyStatus::Draft || $this->wasOpened()) {
            throw InvalidSurveyStateException::cannotDismiss($this->id->value, $this->status->value);
        }

        $this->dismissedAt = $now;
        $this->scheduledSendAt = null;
    }

    /**
     * A subject's end survey made before the subject ended: it waits for SurveySubjectEnded, then
     * goes out or is offered as chosen. Opening it some other way (from the panel) stops the wait.
     */
    public function isAwaitingSubjectEnd(): bool
    {
        return $this->subjectEndKey !== null
            && ! $this->subjectEndedAt instanceof DateTimeImmutable
            && ! $this->wasOpened();
    }

    /**
     * How a survey waiting for its subject's end goes out: by itself, that many hours after the
     * end (0 = at once), or offered to the organizers with one click.
     */
    public function chooseEndSending(bool $sendOnEnd, int $sendDelayHours): void
    {
        if (! $this->isAwaitingSubjectEnd()) {
            throw InvalidSurveyStateException::notAwaitingSubjectEnd($this->id->value);
        }

        if ($sendDelayHours < 0) {
            throw InvalidSurveyStateException::invalidSendDelay($this->id->value);
        }

        $this->sendOnEnd = $sendOnEnd;
        $this->sendDelayHours = $sendDelayHours;
    }

    /**
     * The subject's end survey steps down to an ordinary draft of the subject, while it still
     * waits for the end: another survey (or none) goes out on the end instead, and nothing
     * written in it is lost.
     */
    public function releaseEndKey(): void
    {
        if (! $this->isAwaitingSubjectEnd()) {
            throw InvalidSurveyStateException::notAwaitingSubjectEnd($this->id->value);
        }

        $this->subjectEndKey = null;
        $this->sendOnEnd = false;
        $this->sendDelayHours = 0;
        $this->scheduledSendAt = null;
    }

    /**
     * A draft written for the subject becomes what goes out on its end: only a form about that
     * subject that never opened, was not dismissed and is not already an end survey.
     */
    public function becomeEndSurvey(string $endKey): void
    {
        if ($this->subjectEndKey !== null
            || ! $this->subject instanceof SubjectSnapshot
            || $endKey !== "{$this->subject->sourceType}:{$this->subject->subjectId}"
            || $this->kind !== SurveyKind::Form
            || $this->status !== SurveyStatus::Draft
            || $this->wasOpened()
            || $this->dismissedAt instanceof DateTimeImmutable) {
            throw InvalidSurveyStateException::cannotBecomeEndSurvey($this->id->value);
        }

        $this->subjectEndKey = $endKey;
    }

    /**
     * Whether opening the survey announces it on the channels: only a general survey, chosen
     * while it is a draft (it is announced on its first opening only).
     */
    public function chooseChannelAnnouncement(bool $announce): void
    {
        if ($this->subject instanceof SubjectSnapshot) {
            throw InvalidSurveyStateException::subjectSurveyNotAnnounced($this->id->value);
        }

        if ($this->status !== SurveyStatus::Draft) {
            throw InvalidSurveyStateException::announcementFixed($this->id->value);
        }

        $this->announceInChannels = $announce;
    }

    /**
     * The subject ended: kept from the first time it is told, so a repeated end changes nothing.
     */
    public function markSubjectEnded(DateTimeImmutable $endedAt): void
    {
        $this->subjectEndedAt ??= $endedAt;
    }

    /**
     * The subject as it is now, for a survey prepared before its end: titles, dates and
     * organizers may have changed meanwhile. Fixed once the survey opens.
     */
    public function refreshSubject(SubjectSnapshot $subject): void
    {
        if ($this->wasOpened()) {
            throw InvalidSurveyStateException::subjectFixed($this->id->value);
        }

        if (! $this->subject instanceof SubjectSnapshot
            || $subject->sourceType !== $this->subject->sourceType
            || $subject->subjectId !== $this->subject->subjectId) {
            throw InvalidSurveyStateException::otherSubject($this->id->value);
        }

        $this->subject = $subject;
    }

    /**
     * Takes back a survey its organizers chose not to send, while it never opened.
     */
    public function reconsider(): void
    {
        if ($this->status !== SurveyStatus::Draft || $this->wasOpened()) {
            throw InvalidSurveyStateException::cannotDismiss($this->id->value, $this->status->value);
        }

        $this->dismissedAt = null;
    }

    /**
     * The subject will never end: a survey that never opened is dropped, also while it waits for
     * a moderator (approving it would send it to the audience of something that did not happen).
     */
    public function withdrawWithSubject(DateTimeImmutable $now): void
    {
        if ($this->wasOpened() || ! in_array($this->status, [SurveyStatus::Draft, SurveyStatus::PendingReview], true)) {
            throw InvalidSurveyStateException::cannotDismiss($this->id->value, $this->status->value);
        }

        $this->status = SurveyStatus::Draft;
        $this->dismissedAt = $now;
        $this->scheduledSendAt = null;
    }

    private function assertPendingReview(): void
    {
        if ($this->status !== SurveyStatus::PendingReview) {
            throw InvalidSurveyStateException::notPendingReview($this->id->value, $this->status->value);
        }
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
     * A new token for the public link, when the old one went where it should not: the old link
     * stops working at once. Only while open, so a closed survey's link never comes back.
     *
     * @throws InvalidSurveyStateException
     */
    public function renewPublicToken(string $token): void
    {
        if ($this->status !== SurveyStatus::Open || $this->publicToken === null) {
            throw InvalidSurveyStateException::cannotRenewPublicLink($this->id->value, $this->status->value);
        }

        $this->publicToken = $token;
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
     * @param  PublicLinkMode|null  $publicLinkMode  null keeps the current one
     */
    public function changeAudience(AudienceType $type, array $roles, bool $publicLinkEnabled, ?PublicLinkMode $publicLinkMode = null): void
    {
        $roles = $type === AudienceType::Roles ? $roles : [];
        $publicLinkMode ??= $this->publicLinkMode;
        if ($type === $this->audienceType && $roles === $this->audienceRoles && $publicLinkEnabled === $this->publicLinkEnabled && $publicLinkMode === $this->publicLinkMode) {
            return;
        }

        if ($this->status !== SurveyStatus::Draft) {
            throw InvalidSurveyStateException::audienceFixed($this->id->value);
        }

        $this->audienceType = $type;
        $this->audienceRoles = $roles;
        $this->publicLinkEnabled = $publicLinkEnabled;
        $this->publicLinkMode = $publicLinkMode;
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
        $validator->assertChangeAllowed($this->definition, $definition, $hasResponses, keysFixed: true);

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

    public function publicLinkMode(): PublicLinkMode
    {
        return $this->publicLinkMode;
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

    public function durationDays(): ?int
    {
        return $this->durationDays;
    }

    public function hasUnreviewedChanges(): bool
    {
        return $this->unreviewedChanges;
    }

    public function lastChangedBy(): ?string
    {
        return $this->lastChangedBy;
    }

    public function rejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function submittedBy(): ?string
    {
        return $this->submittedBy;
    }

    public function submittedForReviewAt(): ?DateTimeImmutable
    {
        return $this->submittedForReviewAt;
    }

    public function reviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function reviewedAt(): ?DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function scheduledSendAt(): ?DateTimeImmutable
    {
        return $this->scheduledSendAt;
    }

    public function dismissedAt(): ?DateTimeImmutable
    {
        return $this->dismissedAt;
    }

    public function subjectEndedAt(): ?DateTimeImmutable
    {
        return $this->subjectEndedAt;
    }

    public function sendsOnEnd(): bool
    {
        return $this->sendOnEnd;
    }

    public function sendDelayHours(): int
    {
        return $this->sendDelayHours;
    }

    public function subjectEndKey(): ?string
    {
        return $this->subjectEndKey;
    }

    public function announceInChannels(): bool
    {
        return $this->announceInChannels;
    }
}
