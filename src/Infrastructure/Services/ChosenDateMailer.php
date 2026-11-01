<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Modules\Surveys\Application\Contracts\ChosenDateNotifierInterface;
use Modules\Surveys\Application\Services\AvailabilitySlotFormatter;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Notifications\AvailabilityDateChosen;

/**
 * Mails the fixed date to every account the survey went to and every account that answered it,
 * once each; anonymized accounts get nothing. Guests are not told: their addresses are
 * forgotten when the survey closes.
 */
final readonly class ChosenDateMailer implements ChosenDateNotifierInterface
{
    private const int CHUNK = 200;

    public function __construct(
        private AvailabilitySlotFormatter $slots,
        private SiteBrandingServiceInterface $branding,
    ) {}

    public function dateChosen(Survey $survey): void
    {
        $slot = $survey->chosenSlot();
        if (! $slot instanceof Slot) {
            return;
        }

        $id = $survey->id()->value;
        $userIds = SurveyRecipientModel::query()->where('survey_id', $id)->whereNotNull('user_id')->select('user_id')
            ->union(SurveyParticipationModel::query()->where('survey_id', $id)->whereNotNull('user_id')->select('user_id'));

        $mail = new AvailabilityDateChosen(
            surveyTitle: $survey->title(),
            date: $this->slots->label($slot),
            siteName: $this->branding->siteName(),
            subjectTitle: $survey->subject()?->title,
            subjectUrl: $survey->subject()?->url,
        );

        UserModel::query()
            ->whereIn('id', $userIds)
            ->whereNull('anonymized_at')
            ->chunkById(self::CHUNK, static function ($users) use ($mail): void {
                foreach ($users as $user) {
                    $user->notify($mail);
                }
            });
    }
}
