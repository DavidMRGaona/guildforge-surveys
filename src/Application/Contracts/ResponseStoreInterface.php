<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use DateTimeImmutable;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

interface ResponseStoreInterface
{
    public function isRecipient(SurveyId $surveyId, string $userId): bool;

    /**
     * The display name of the guest whose link token has this hash, or null when no guest of
     * this survey has it.
     */
    public function guestName(SurveyId $surveyId, string $tokenHash): ?string;

    /**
     * Whether this account or guest link has already answered: only participations are read.
     */
    public function hasParticipated(SurveyId $surveyId, ?string $userId, ?string $tokenHash): bool;

    /**
     * Stores the participation ("already answered") and the response with its values, all or
     * nothing. Nothing links one to the other.
     *
     * @param  string|null  $participantUserId  account that answered (null for guests and the public link)
     * @param  string|null  $participantTokenHash  guest link hash (null for accounts and the public link)
     * @param  DateTimeImmutable  $submittedOn  only its day is kept
     * @param  string|null  $responseUserId  only on named surveys
     * @param  string|null  $responseGuestName  only on named surveys
     * @param  list<AnswerValue>  $values
     *
     * @throws AlreadyAnsweredException
     */
    public function record(
        SurveyId $surveyId,
        ?string $participantUserId,
        ?string $participantTokenHash,
        DateTimeImmutable $submittedOn,
        ?string $responseUserId,
        ?string $responseGuestName,
        array $values,
    ): void;
}
