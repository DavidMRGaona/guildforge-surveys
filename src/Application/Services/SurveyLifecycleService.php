<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\AudienceResolverInterface;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveyRecipientStoreInterface;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
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
     * The survey made when a subject ends: a copy of the template (keys included) about the
     * subject, as a draft that lasts $durationDays once it opens. The end key keeps it unique.
     *
     * @throws SubjectNotFoundException
     */
    public function createFromTemplateForSubject(
        string $sourceType,
        string $subjectId,
        SurveyTemplate $template,
        bool $anonymous,
        int $durationDays,
        ?string $subjectEndKey,
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
            durationDays: $durationDays,
            subjectEndKey: $subjectEndKey,
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
