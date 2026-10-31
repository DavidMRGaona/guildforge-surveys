<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\ResponseStoreInterface;
use Modules\Surveys\Application\DTOs\GuestRecipient;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyAnswerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;

final class EloquentResponseStore implements ResponseStoreInterface
{
    public function isRecipient(SurveyId $surveyId, string $userId): bool
    {
        return SurveyRecipientModel::query()->where('survey_id', $surveyId->value)->where('user_id', $userId)->exists();
    }

    public function guestRecipient(SurveyId $surveyId, string $tokenHash): ?GuestRecipient
    {
        $recipient = SurveyRecipientModel::query()
            ->where('survey_id', $surveyId->value)
            ->where('token_hash', $tokenHash)
            ->first();

        return $recipient === null ? null : new GuestRecipient($recipient->guest_name, $recipient->source === SurveyRecipientModel::SOURCE_PUBLIC_LINK);
    }

    public function hasParticipated(SurveyId $surveyId, ?string $userId, ?string $tokenHash): bool
    {
        if ($userId === null && $tokenHash === null) {
            return false;
        }

        return SurveyParticipationModel::query()
            ->where('survey_id', $surveyId->value)
            ->when($userId !== null, static fn ($query) => $query->where('user_id', $userId))
            ->when($userId === null, static fn ($query) => $query->where('token_hash', $tokenHash))
            ->exists();
    }

    public function record(
        SurveyId $surveyId,
        ?string $participantUserId,
        ?string $participantTokenHash,
        DateTimeImmutable $submittedOn,
        ?string $responseUserId,
        ?string $responseGuestName,
        array $values,
    ): void {
        try {
            DB::transaction(static function () use ($surveyId, $participantUserId, $participantTokenHash, $submittedOn, $responseUserId, $responseGuestName, $values): void {
                $verified = $participantUserId !== null || $participantTokenHash !== null;
                if ($verified) {
                    // The unique indexes settle a race between two submissions of the same person
                    SurveyParticipationModel::query()->create([
                        'survey_id' => $surveyId->value,
                        'user_id' => $participantUserId,
                        'token_hash' => $participantTokenHash,
                    ]);
                }

                $response = SurveyResponseModel::query()->create([
                    'survey_id' => $surveyId->value,
                    'submitted_on' => $submittedOn->format('Y-m-d'),
                    'user_id' => $responseUserId,
                    'guest_name' => $responseGuestName,
                    // Only says whether it came with a participation, never whose
                    'verified' => $verified,
                ]);

                foreach ($values as $value) {
                    SurveyAnswerModel::query()->create([
                        'response_id' => $response->id,
                        'question_id' => $value->questionId,
                        'option_id' => $value->optionId,
                        'row_id' => $value->rowId,
                        'text_value' => $value->text,
                        'number_value' => $value->number,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            throw AlreadyAnsweredException::for($surveyId->value);
        }
    }
}
