<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Activity;

use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\Eloquent\Builder;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;

/**
 * What counts as «a survey answered» for the activity contract, once. The source and the code that
 * publishes changes both ask it, so they can never disagree about what a subject is.
 *
 * A response counts when somebody could not learn anything restricted from it:
 *
 * - the survey is **named**. An anonymous survey reports nothing, ever: its responses keep no
 *   person, and the flag is checked on the survey as it is now, so even a response that somehow
 *   held a person would be left out (and a survey never becomes named again);
 * - the survey is **sent to every account** (`all_users`). One for association members, for some
 *   roles or for the people of a subject would say that its respondent is a socio, holds a role or
 *   took part in something, and a member outside that audience cannot even know it exists;
 * - the survey is **open or closed**. A draft was never sent, and one waiting for review is paused
 *   until a moderator approves it;
 * - the response belongs to an **account** that still exists (deactivated included: whether they
 *   count is the consumer's choice, and the core's reader drops anonymized ones). A guest never does.
 *
 * It knows nothing about who is looking, so every answer is the same for everybody.
 */
final readonly class CountedResponses
{
    /**
     * Surveys whose responses may count.
     *
     * @return Builder<SurveyModel>
     */
    public function surveys(): Builder
    {
        return SurveyModel::query()
            ->where('anonymous', false)
            ->where('audience_type', AudienceType::AllUsers->value)
            ->whereIn('status', $this->statuses());
    }

    /**
     * The same rule for a survey that is not (or no longer) in the table, say the values a save
     * just replaced or a row about to be deleted.
     */
    public function surveyCounts(?string $status, ?string $audienceType, bool $anonymous): bool
    {
        return ! $anonymous
            && $audienceType === AudienceType::AllUsers->value
            && in_array($status, $this->statuses(), true);
    }

    /**
     * Every response that counts.
     *
     * @return Builder<SurveyResponseModel>
     */
    public function responses(): Builder
    {
        return SurveyResponseModel::query()
            ->whereNotNull('user_id')
            ->whereIn('survey_id', $this->surveys()->select('id'))
            // No foreign key: an account removed for good leaves its responses behind
            ->whereIn('user_id', UserModel::withTrashed()->select('id'));
    }

    /**
     * @return list<string>
     */
    private function statuses(): array
    {
        return [SurveyStatus::Open->value, SurveyStatus::Closed->value];
    }
}
