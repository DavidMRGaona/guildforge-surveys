<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use App\Application\Services\TimezoneServiceInterface;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\InvalidSubmissionException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\Exceptions\SurveyNotAcceptingResponsesException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\SubmissionValidator;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Takes a response. On an anonymous survey the response keeps no link to the person: their
 * participation goes apart, and only the day is kept. Answers never reach a log.
 */
final readonly class SubmissionService
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private ResponseStoreInterface $responses,
        private TimezoneServiceInterface $timezone,
    ) {}

    /**
     * @param  array<mixed>  $input  answers keyed by question id (see Submission::fromInput())
     *
     * @throws SurveyNotAcceptingResponsesException
     * @throws NotInvitedException
     * @throws InvalidSubmissionException
     * @throws AlreadyAnsweredException
     */
    public function submit(SurveyId $id, Respondent $respondent, array $input): void
    {
        $survey = $this->surveys->findOrFail($id);
        $now = now()->toDateTimeImmutable();
        if (! $survey->isAcceptingResponses($now)) {
            throw SurveyNotAcceptingResponsesException::for($id->value);
        }

        [$participantUserId, $participantTokenHash, $guestName] = $this->identify($survey, $respondent);

        $submission = Submission::fromInput($survey->definition(), $input);
        $errors = (new SubmissionValidator)->validate($survey->definition(), $submission);
        if ($errors !== []) {
            throw InvalidSubmissionException::withErrors($errors);
        }

        $named = ! $survey->isAnonymous();
        $this->responses->record(
            surveyId: $id,
            participantUserId: $participantUserId,
            participantTokenHash: $participantTokenHash,
            submittedOn: $this->timezone->toDisplay($now),
            responseUserId: $named ? $participantUserId : null,
            responseGuestName: $named ? $guestName : null,
            values: $submission->values,
        );
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
            $name = $this->responses->guestName($id, $hash);
            if ($name !== null) {
                return [null, $hash, $name];
            }
        }

        $publicToken = $survey->publicToken();
        if ($respondent->kind === Respondent::PUBLIC_LINK && $respondent->token !== null && $publicToken !== null && hash_equals($publicToken, $respondent->token)) {
            return [null, null, null];
        }

        throw NotInvitedException::for($id->value);
    }
}
