<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\Exceptions\SubjectCancelledException;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;

/**
 * Asking the same again: a survey copied as a draft (Survey::duplicate() says what is kept) or
 * kept as a template. A subject survey's copy is about the subject as it is now, never one that
 * is gone or cancelled. A copy made on the public site counts as content made outside the
 * panel, so sending it waits for a moderator when the review setting is on.
 */
final readonly class SurveyDuplicationService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyTemplateRepositoryInterface $templates,
        private SurveyManagerGate $gate,
        private SurveySubjectSourceRegistryInterface $sources,
        private SurveySettingsInterface $settings,
    ) {}

    /**
     * The panel's copy, about the same subject (or general, like the original). It does not ask
     * who is copying: the panel checks.
     *
     * @throws ModelNotFoundException
     * @throws SubjectNotFoundException
     * @throws SubjectCancelledException
     */
    public function duplicate(SurveyId $id, string $userId, ?DateTimeImmutable $now = null): Survey
    {
        $original = $this->surveys->findOrFail($id);
        $subject = $original->subject();
        $snapshot = $subject instanceof SubjectSnapshot ? $this->snapshot($this->find($subject->sourceType, $subject->subjectId)) : null;

        $copy = $original->duplicate(
            SurveyId::generate(),
            $this->copyTitle($original->title()),
            $snapshot,
            $userId,
            $this->closingDate($now, $this->settings->defaultCloseDays()),
        );
        $this->surveys->save($copy);

        return $copy;
    }

    /**
     * An organizer's copy of a survey they manage, to its subject or to another subject they
     * manage (asked live of both). Copied to another subject it keeps its title.
     *
     * @throws ModelNotFoundException
     * @throws AuthorizationException
     * @throws SubjectNotFoundException
     * @throws SubjectCancelledException
     */
    public function duplicateForOrganizer(SurveyId $id, string $userId, ?string $sourceType = null, ?string $subjectId = null, ?DateTimeImmutable $now = null): Survey
    {
        $original = $this->surveys->findOrFail($id);
        $from = $original->subject();
        if (! $from instanceof SubjectSnapshot || ! $this->gate->canManage($userId, $original)) {
            throw new AuthorizationException;
        }

        $sourceType ??= $from->sourceType;
        $subjectId ??= $from->subjectId;
        $target = $this->find($sourceType, $subjectId);
        if (! $this->sources->source($sourceType)?->canManage($userId, $subjectId)) {
            throw new AuthorizationException;
        }

        $sameSubject = $sourceType === $from->sourceType && $subjectId === $from->subjectId;
        $copy = $original->duplicate(
            SurveyId::generate(),
            $sameSubject ? $this->copyTitle($original->title()) : $original->title(),
            $this->snapshot($target),
            $userId,
            $this->closingDate($now, $this->settings->forSubjectType($sourceType)->closeDays),
        );
        $copy->markUnreviewedChanges($userId);
        $this->surveys->save($copy);

        return $copy;
    }

    /**
     * A template with the survey's questions, as they are (keys only where they were), meant for
     * the survey's kind of subject (or general surveys). It does not ask who is saving it:
     * callers check.
     *
     * @throws ModelNotFoundException
     * @throws InvalidSurveyDefinitionException
     */
    public function saveAsTemplate(SurveyId $id, string $name, ?string $description, string $userId): SurveyTemplate
    {
        $survey = $this->surveys->findOrFail($id);
        (new SurveyDefinitionValidator)->assertValid($survey->definition(), $survey->kind());
        $subject = $survey->subject();

        $template = new SurveyTemplate(
            id: SurveyTemplateId::generate(),
            name: $name,
            description: $description,
            kind: $survey->kind(),
            definition: $survey->definition(),
            createdBy: $userId,
            uses: new TemplateUses([$subject instanceof SubjectSnapshot ? $subject->sourceType : TemplateUses::GENERAL]),
        );
        $this->templates->save($template);

        return $template;
    }

    /**
     * @throws SubjectNotFoundException
     */
    private function find(string $sourceType, string $subjectId): SurveySubjectDTO
    {
        return $this->sources->source($sourceType)?->find($subjectId)
            ?? throw SubjectNotFoundException::for($sourceType, $subjectId);
    }

    /**
     * @throws SubjectCancelledException
     */
    private function snapshot(SurveySubjectDTO $subject): SubjectSnapshot
    {
        if ($subject->status === SurveySubjectStatus::Cancelled) {
            throw SubjectCancelledException::for($subject->sourceType, $subject->subjectId);
        }

        return SubjectSnapshotFactory::fromDto($subject);
    }

    private function copyTitle(string $title): string
    {
        return mb_substr((string) __('surveys::messages.duplicate.copy_title', ['title' => $title]), 0, 255);
    }

    /**
     * For a copy of a survey that closed on a fixed date: as a new survey would, from now.
     */
    private function closingDate(?DateTimeImmutable $now, int $days): DateTimeImmutable
    {
        return CarbonImmutable::instance($now ?? now())->addDays($days)->startOfHour()->toDateTimeImmutable();
    }
}
