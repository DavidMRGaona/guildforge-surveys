<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\AudienceResolverInterface;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveyRecipientStoreInterface;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\DTOs\PublicLinkDTO;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Creates subject surveys, opens surveys (fixing who they go to) and closes them.
 */
final readonly class SurveyLifecycleService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private AudienceResolverInterface $audiences,
        private SurveyRecipientStoreInterface $recipients,
        private SurveySubjectSourceRegistryInterface $sources,
        private SurveyNotifierInterface $notifier,
    ) {}

    /**
     * A draft survey about a module's record, with a snapshot of it taken now.
     *
     * @throws SubjectNotFoundException
     * @throws InvalidSurveyDefinitionException
     */
    public function createForSubject(
        string $sourceType,
        string $subjectId,
        string $title,
        SurveyDefinition $definition,
        bool $anonymous,
        ?DateTimeImmutable $closesAt,
    ): SurveyId {
        $subject = $this->sources->source($sourceType)?->find($subjectId);
        if (! $subject instanceof SurveySubjectDTO) {
            throw SubjectNotFoundException::for($sourceType, $subjectId);
        }

        (new SurveyDefinitionValidator)->assertValid($definition, SurveyKind::Form);

        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: $title,
            description: null,
            definition: $definition,
            anonymous: $anonymous,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::Subject,
            subject: SubjectSnapshotFactory::fromDto($subject),
            closesAt: $closesAt,
        );
        $this->surveys->save($survey);

        return $survey->id();
    }

    /**
     * A subject's survey from a template: a copy (keys included) about the subject, as a draft
     * that lasts $durationDays once it opens. An end survey carries the end key, which keeps it
     * unique, and when the end arrived (null while an organizer's prepared survey waits for it).
     *
     * @throws SubjectNotFoundException
     * @throws UniqueConstraintViolationException when another survey already has the end key
     */
    public function createFromTemplateForSubject(
        string $sourceType,
        string $subjectId,
        SurveyTemplate $template,
        bool $anonymous,
        int $durationDays,
        ?string $subjectEndKey,
        ?string $createdBy = null,
        ?DateTimeImmutable $subjectEndedAt = null,
    ): Survey {
        $subject = $this->sources->source($sourceType)?->find($subjectId);
        if (! $subject instanceof SurveySubjectDTO) {
            throw SubjectNotFoundException::for($sourceType, $subjectId);
        }

        $survey = new Survey(
            id: SurveyId::generate(),
            kind: $template->kind(),
            title: $template->name(),
            description: $template->description(),
            definition: $template->definition(),
            anonymous: $anonymous,
            results: ResultsSettings::defaultsFor($template->kind()),
            audienceType: AudienceType::Subject,
            templateId: $template->id(),
            subject: SubjectSnapshotFactory::fromDto($subject),
            createdBy: $createdBy,
            durationDays: $durationDays,
            subjectEndKey: $subjectEndKey,
            subjectEndedAt: $subjectEndedAt,
        );
        $this->surveys->save($survey);

        return $survey;
    }

    /**
     * Opens the survey, fixes its audience (whoever joins later does not get it) and mails it.
     * A survey that had opened before (back from a review) resumes: its audience and links stay
     * as fixed then, and nobody is mailed again.
     *
     * @throws InvalidSurveyStateException
     */
    public function open(SurveyId $id, ?DateTimeImmutable $now = null, bool $notify = true): OpenedSurveyDTO
    {
        $survey = $this->surveys->findOrFail($id);

        if ($survey->wasOpened()) {
            $survey->open($now ?? now()->toDateTimeImmutable());
            $this->surveys->save($survey);

            return new OpenedSurveyDTO($survey->id(), [], $survey->publicToken());
        }

        $audience = $this->audiences->resolve($survey);

        $guestTokens = [];
        foreach ($audience->guests as $guest) {
            $guestTokens[$guest->reference] = $this->token();
        }
        $survey->open($now ?? now()->toDateTimeImmutable(), $audience->count(), $survey->isPublicLinkEnabled() ? $this->token() : null);

        DB::transaction(function () use ($survey, $audience, $guestTokens): void {
            $this->surveys->save($survey);
            $this->recipients->fix($survey->id(), $audience, $guestTokens);
        });

        if ($notify) {
            $this->notifier->notifyOpened($survey->id());
        }

        return new OpenedSurveyDTO($survey->id(), $guestTokens, $survey->publicToken());
    }

    /**
     * The public link to share, only while the survey takes responses: a draft has none yet, and
     * a closed survey (or one waiting for review) would only show a link that refuses answers.
     */
    public function publicLink(SurveyId $id, ?DateTimeImmutable $now = null): ?PublicLinkDTO
    {
        $survey = $this->surveys->find($id);
        $token = $survey?->publicToken();
        if (! $survey instanceof Survey || $token === null || ! $survey->isAcceptingResponses($now ?? now()->toDateTimeImmutable())) {
            return null;
        }

        return new PublicLinkDTO($token, $survey->publicLinkMode());
    }

    /**
     * Replaces the public link's token: the old link stops working, the personal links already
     * mailed from it keep theirs.
     *
     * @throws InvalidSurveyStateException
     */
    public function renewPublicLink(SurveyId $id): PublicLinkDTO
    {
        $survey = $this->surveys->findOrFail($id);
        $survey->renewPublicToken($this->token());
        $this->surveys->save($survey);

        return new PublicLinkDTO((string) $survey->publicToken(), $survey->publicLinkMode());
    }

    /**
     * Closes the survey and forgets its guests' email addresses.
     *
     * @throws InvalidSurveyStateException
     */
    public function close(SurveyId $id, ?DateTimeImmutable $now = null): void
    {
        $survey = $this->surveys->findOrFail($id);
        $survey->close($now ?? now()->toDateTimeImmutable());

        DB::transaction(function () use ($survey): void {
            $this->surveys->save($survey);
            $this->recipients->forgetGuestEmails($survey->id());
        });
    }

    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }
}
