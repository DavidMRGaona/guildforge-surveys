<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Notifications\SurveyInvitation;

/**
 * Each recipient is claimed (notified_at, reminded_at) before it is mailed, in one update
 * that only one run can win, so overlapping runs never mail anybody twice.
 */
final readonly class SurveyInvitationSender implements SurveyNotifierInterface
{
    private const int CHUNK = 200;

    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SiteBrandingServiceInterface $branding,
        private TimezoneServiceInterface $timezone,
    ) {}

    public function notifyOpened(SurveyId $surveyId): void
    {
        $survey = $this->surveys->findOrFail($surveyId);

        $this->send($survey, 'notified_at', SurveyRecipientModel::query()->where('survey_id', $surveyId->value), reminder: false);
    }

    public function remindPending(SurveyId $surveyId): int
    {
        $survey = $this->surveys->findOrFail($surveyId);
        $answered = SurveyParticipationModel::query()->where('survey_id', $surveyId->value);

        $pending = SurveyRecipientModel::query()
            ->where('survey_id', $surveyId->value)
            ->where(static fn (Builder $query): Builder => $query
                ->where(static fn (Builder $accounts): Builder => $accounts
                    ->whereNotNull('user_id')
                    ->whereNotIn('user_id', (clone $answered)->whereNotNull('user_id')->select('user_id')))
                ->orWhere(static fn (Builder $guests): Builder => $guests
                    ->whereNotNull('token_hash')
                    ->whereNotIn('token_hash', (clone $answered)->whereNotNull('token_hash')->select('token_hash'))));

        return $this->send($survey, 'reminded_at', $pending, reminder: true);
    }

    /**
     * @param  Builder<SurveyRecipientModel>  $recipients
     */
    private function send(Survey $survey, string $claimColumn, Builder $recipients, bool $reminder): int
    {
        $sent = 0;
        $closesAt = $survey->closesAt() instanceof DateTimeImmutable ? $this->timezone->format($survey->closesAt()) : null;
        $siteName = $this->branding->siteName();

        $recipients->whereNull($claimColumn)->chunkById(self::CHUNK, function ($chunk) use ($survey, $claimColumn, $reminder, $closesAt, $siteName, &$sent): void {
            foreach ($chunk as $recipient) {
                /** @var SurveyRecipientModel $recipient */
                $claimed = SurveyRecipientModel::query()->whereKey($recipient->id)->whereNull($claimColumn)->update([$claimColumn => now()]);
                if ($claimed !== 1) {
                    continue;
                }

                $url = $this->urlFor($survey, $recipient);
                if ($url === null) {
                    continue;
                }

                $mail = new SurveyInvitation($survey->title(), $url, $closesAt, $survey->isAnonymous(), $reminder, $siteName);

                if ($recipient->user_id !== null) {
                    $user = UserModel::query()->whereKey($recipient->user_id)->whereNull('anonymized_at')->first();
                    $user?->notify($mail);
                    $sent += $user === null ? 0 : 1;
                } elseif ($recipient->guest_email !== null) {
                    Notification::route('mail', $recipient->guest_email)->notify($mail);
                    $sent++;
                }
            }
        });

        return $sent;
    }

    private function urlFor(Survey $survey, SurveyRecipientModel $recipient): ?string
    {
        if ($recipient->user_id !== null) {
            return route('surveys.respond', $survey->id()->value);
        }

        return $recipient->token === null ? null : route('surveys.guest', [$survey->id()->value, $recipient->token]);
    }
}
