<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Services\TimezoneServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Surveys\Application\Contracts\AnswerFileStoreInterface;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\DTOs\GuestRecipient;
use Modules\Surveys\Application\DTOs\IncomingFile;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\FileKind;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\InvalidSubmissionException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\Exceptions\SurveyNotAcceptingResponsesException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\QuizScorer;
use Modules\Surveys\Domain\Services\SubmissionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\FileSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\QuizSettings;
use Modules\Surveys\Domain\ValueObjects\Submission\QuizScore;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Throwable;

/**
 * Takes a response. On an anonymous survey the response keeps no link to the person: their
 * participation goes apart, and only the day is kept. Answers never reach a log.
 */
final readonly class SubmissionService
{
    public const string STATE_OPEN = 'open';

    public const string STATE_ANSWERED = 'answered';

    public const string STATE_CLOSED = 'closed';

    /** Waiting for a moderator after a change: it takes responses again once approved. */
    public const string STATE_PAUSED = 'paused';

    /** The verified public link: it asks for an address before anyone may answer. */
    public const string STATE_VERIFY_EMAIL = 'verify_email';

    public const string STATE_NOT_INVITED = 'not_invited';

    public const string STATE_NOT_FOUND = 'not_found';

    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private ResponseStoreInterface $responses,
        private TimezoneServiceInterface $timezone,
        private AnswerFileStoreInterface $files,
        private SurveySettingsInterface $settings,
    ) {}

    /**
     * Returns the score when the survey is a quiz. It is never stored: results work it out
     * again from the answers.
     *
     * @param  array<mixed>  $input  answers keyed by question id (see Submission::fromInput())
     * @param  list<IncomingFile>  $files  the files sent for file questions; nothing else answers them
     *
     * @throws SurveyNotAcceptingResponsesException
     * @throws NotInvitedException
     * @throws InvalidSubmissionException
     * @throws AlreadyAnsweredException
     */
    public function submit(SurveyId $id, Respondent $respondent, array $input, array $files = []): ?QuizScore
    {
        // The survey's row stays locked until the response is stored, so an organizer's edit
        // (which locks it too) never changes the content under a first response
        return DB::transaction(fn (): ?QuizScore => $this->submitLocked($id, $respondent, $input, $files));
    }

    /**
     * @param  array<mixed>  $input
     * @param  list<IncomingFile>  $files
     *
     * @throws SurveyNotAcceptingResponsesException
     * @throws NotInvitedException
     * @throws InvalidSubmissionException
     * @throws AlreadyAnsweredException
     */
    private function submitLocked(SurveyId $id, Respondent $respondent, array $input, array $files): ?QuizScore
    {
        $survey = $this->surveys->findForUpdate($id);
        $now = now()->toDateTimeImmutable();
        if (! $survey->isAcceptingResponses($now)) {
            throw SurveyNotAcceptingResponsesException::for($id->value);
        }

        [$participantUserId, $participantTokenHash, $guestName] = $this->identify($survey, $respondent);

        [$input, $accepted, $fileErrors] = $this->acceptFiles($survey, $input, $files);
        $submission = Submission::fromInput($survey->definition(), $input);
        $errors = [...(new SubmissionValidator)->validate($survey->definition(), $submission), ...$fileErrors];
        if ($errors !== []) {
            throw InvalidSubmissionException::withErrors($errors);
        }

        $named = ! $survey->isAnonymous();
        $stored = [];
        try {
            foreach ($accepted as [$fileId, $file, $contentType]) {
                $stored[] = $this->files->store($id, $fileId, $file, $contentType, keepName: $named)
                    ?? throw InvalidSubmissionException::withErrors([$file->questionId => 'file_type']);
            }

            $this->responses->record(
                surveyId: $id,
                participantUserId: $participantUserId,
                participantTokenHash: $participantTokenHash,
                submittedOn: $this->timezone->toDisplay($now),
                responseUserId: $named ? $participantUserId : null,
                responseGuestName: $named ? $guestName : null,
                values: $submission->values,
                files: $stored,
            );
        } catch (Throwable $exception) {
            $this->files->discard($stored);

            throw $exception;
        }

        return $survey->definition()->quiz instanceof QuizSettings ? (new QuizScorer)->score($survey->definition(), $submission) : null;
    }

    /**
     * A file question is only answered by a file: whatever text came for it is dropped, and each
     * file sent gets the id its answer will hold. A file is refused when its contents are not a
     * kind the question takes (no PDF on an anonymous survey) or it is larger than the question
     * or the module allow.
     *
     * @param  array<mixed>  $input
     * @param  list<IncomingFile>  $files
     * @return array{array<mixed>, list<array{string, IncomingFile, string}>, array<string, string>} the input, the accepted
     *                                                                                               files (id, file, content type) and the errors per question
     */
    private function acceptFiles(Survey $survey, array $input, array $files): array
    {
        $sent = [];
        foreach ($files as $file) {
            $sent[$file->questionId] = $file;
        }

        $accepted = [];
        $errors = [];
        foreach ($survey->definition()->questions() as $question) {
            if ($question->type !== QuestionType::File || ! $question->file instanceof FileSettings) {
                continue;
            }

            unset($input[$question->id]);
            $file = $sent[$question->id] ?? null;
            if (! $file instanceof IncomingFile) {
                continue;
            }

            $contentType = $this->files->contentType($file);
            $kind = $contentType === null ? null : FileKind::forContentType($contentType);
            $maxBytes = min($question->file->maxMb, $this->settings->maxFileMb()) * 1024 * 1024;
            $error = match (true) {
                $file->size > $maxBytes => 'file_too_large',
                $contentType === null || ! $kind instanceof FileKind || ! $question->file->accepts($kind) => 'file_type',
                $kind === FileKind::Pdf && $survey->isAnonymous() => 'file_type',
                ! $this->files->fits($file, $contentType) => 'file_too_large',
                default => null,
            };
            if ($error !== null) {
                $errors[$question->id] = $error;

                continue;
            }

            $fileId = (string) Str::uuid();
            $input[$question->id] = $fileId;
            $accepted[] = [$fileId, $file, (string) $contentType];
        }

        return [$input, $accepted, $errors];
    }

    /**
     * What the respondent finds when opening the survey, decided as submit() would.
     */
    public function stateFor(SurveyId $id, Respondent $respondent): string
    {
        $survey = $this->surveys->find($id);
        if (! $survey instanceof Survey) {
            return self::STATE_NOT_FOUND;
        }

        if ($this->isVerifiedPublicLink($survey, $respondent)) {
            return match (true) {
                $survey->status() === SurveyStatus::PendingReview => self::STATE_PAUSED,
                $survey->isAcceptingResponses(now()->toDateTimeImmutable()) => self::STATE_VERIFY_EMAIL,
                default => self::STATE_CLOSED,
            };
        }

        try {
            [$userId, $tokenHash] = $this->identify($survey, $respondent);
        } catch (NotInvitedException) {
            return self::STATE_NOT_INVITED;
        }

        if ($this->responses->hasParticipated($id, $userId, $tokenHash)) {
            return self::STATE_ANSWERED;
        }

        if ($survey->status() === SurveyStatus::PendingReview) {
            return self::STATE_PAUSED;
        }

        return $survey->isAcceptingResponses(now()->toDateTimeImmutable()) ? self::STATE_OPEN : self::STATE_CLOSED;
    }

    /**
     * @return array{?string, ?string, ?string} participant user id, participant token hash, guest name
     *
     * @throws NotInvitedException
     */
    private function identify(Survey $survey, Respondent $respondent): array
    {
        $id = $survey->id();

        if ($respondent->kind === Respondent::ACCOUNT && $respondent->userId !== null && $this->responses->isRecipient($id, $respondent->userId)) {
            return [$respondent->userId, null, null];
        }

        if ($respondent->kind === Respondent::GUEST && $respondent->token !== null) {
            $hash = hash('sha256', $respondent->token);
            $guest = $this->responses->guestRecipient($id, $hash);
            if ($guest instanceof GuestRecipient) {
                return [null, $hash, $guest->name];
            }
        }

        // The verified public link only hands out single-use links: nobody answers through it
        if ($this->holdsPublicLink($survey, $respondent) && $survey->publicLinkMode() === PublicLinkMode::Open) {
            return [null, null, null];
        }

        throw NotInvitedException::for($id->value);
    }

    /**
     * Whether whoever answers came through the public link, directly or with a link it mailed.
     * Their names are not the audience's: a named poll never shows them who voted.
     */
    public function comesFromPublicLink(SurveyId $id, Respondent $respondent): bool
    {
        if ($respondent->kind === Respondent::PUBLIC_LINK) {
            return true;
        }

        return $respondent->kind === Respondent::GUEST && $respondent->token !== null
            && $this->responses->guestRecipient($id, hash('sha256', $respondent->token))?->fromPublicLink === true;
    }

    private function isVerifiedPublicLink(Survey $survey, Respondent $respondent): bool
    {
        return $this->holdsPublicLink($survey, $respondent) && $survey->publicLinkMode() === PublicLinkMode::VerifiedEmail;
    }

    private function holdsPublicLink(Survey $survey, Respondent $respondent): bool
    {
        $publicToken = $survey->publicToken();

        return $respondent->kind === Respondent::PUBLIC_LINK && $respondent->token !== null && $publicToken !== null && hash_equals($publicToken, $respondent->token);
    }
}
