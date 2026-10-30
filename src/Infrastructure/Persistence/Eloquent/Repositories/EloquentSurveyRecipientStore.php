<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use Modules\Surveys\Application\Contracts\SurveyRecipientStoreInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;

final class EloquentSurveyRecipientStore implements SurveyRecipientStoreInterface
{
    public function fix(SurveyId $surveyId, SurveyAudienceDTO $audience, array $guestTokens): void
    {
        foreach ($audience->userIds as $userId) {
            SurveyRecipientModel::query()->create(['survey_id' => $surveyId->value, 'user_id' => $userId]);
        }

        foreach ($audience->guests as $guest) {
            SurveyRecipientModel::query()->create([
                'survey_id' => $surveyId->value,
                'guest_reference' => $guest->reference,
                'guest_name' => $guest->displayName,
                'guest_email' => $guest->email,
                'token' => $guestTokens[$guest->reference] ?? null,
                'token_hash' => isset($guestTokens[$guest->reference]) ? hash('sha256', $guestTokens[$guest->reference]) : null,
            ]);
        }
    }

    public function forgetGuestEmails(SurveyId $surveyId): void
    {
        SurveyRecipientModel::query()
            ->where('survey_id', $surveyId->value)
            ->where(static fn ($query) => $query->whereNotNull('guest_email')->orWhereNotNull('token'))
            ->update(['guest_email' => null, 'token' => null]);
    }
}
