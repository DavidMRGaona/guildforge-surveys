<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Support;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;

/**
 * Surveys and responses written straight to the tables, so a test can put a survey in any state,
 * including the ones the domain refuses (a named response on an anonymous survey). The default
 * survey is the one that counts for the activity contract: named, open, sent to every account.
 */
trait BuildsActivityResponses
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function activitySurvey(array $overrides = []): SurveyModel
    {
        return SurveyModel::query()->create(array_merge([
            'kind' => 'form',
            'title' => 'Encuesta de ejemplo '.uniqid(),
            'definition' => ['sections' => []],
            'status' => 'open',
            'anonymous' => false,
            'results_settings' => [],
            'audience_type' => 'all_users',
            'audience_roles' => [],
        ], $overrides));
    }

    /**
     * A response of an account (a guest's when $user is null and a guest name is given).
     */
    private function activityResponse(SurveyModel $survey, ?UserModel $user = null, string $day = '2030-03-04', ?string $guestName = null): SurveyResponseModel
    {
        return SurveyResponseModel::query()->create([
            'survey_id' => $survey->id,
            'submitted_on' => $day,
            'user_id' => $user?->id,
            'guest_name' => $guestName,
            'verified' => true,
        ]);
    }

    /**
     * An account that has been invited to the survey, which is what lets it open the page.
     */
    private function invite(SurveyModel $survey, UserModel $user): void
    {
        SurveyRecipientModel::query()->create([
            'survey_id' => $survey->id,
            'user_id' => $user->id,
            'source' => SurveyRecipientModel::SOURCE_AUDIENCE,
        ]);
    }

    /**
     * "Already answered" of an account: the only thing an anonymous survey keeps about whoever
     * answered it, and never anything the activity source may read.
     */
    private function participation(SurveyModel $survey, UserModel $user): void
    {
        SurveyParticipationModel::query()->create(['survey_id' => $survey->id, 'user_id' => $user->id]);
    }
}
