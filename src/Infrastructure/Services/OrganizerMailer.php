<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Application\Services\TimezoneServiceInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\OrganizerNotifierInterface;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Notifications\SurveyReadyToSend;
use Modules\Surveys\Notifications\SurveyScheduled;

/**
 * Mails the organizers of a survey's subject who still manage it (asked live) and whose
 * account is active. Each mail links to the subject's page, where its surveys are managed.
 */
final readonly class OrganizerMailer implements OrganizerNotifierInterface
{
    public function __construct(
        private SurveyManagerGate $gate,
        private SiteBrandingServiceInterface $branding,
        private TimezoneServiceInterface $timezone,
    ) {}

    public function readyToSend(Survey $survey): void
    {
        $subject = $survey->subject();
        if (! $subject instanceof SubjectSnapshot) {
            return;
        }

        $this->mail($survey, new SurveyReadyToSend($subject->title, $this->url($subject), $this->branding->siteName()));
    }

    public function scheduled(Survey $survey): void
    {
        $subject = $survey->subject();
        $sendsAt = $survey->scheduledSendAt();
        if (! $subject instanceof SubjectSnapshot || ! $sendsAt instanceof DateTimeImmutable) {
            return;
        }

        $this->mail($survey, new SurveyScheduled($subject->title, $this->timezone->format($sendsAt), $this->url($subject), $this->branding->siteName()));
    }

    private function mail(Survey $survey, BaseNotification $notification): void
    {
        $organizers = UserModel::query()
            ->whereIn('id', $this->gate->managingOrganizers($survey))
            ->whereNull('anonymized_at')
            ->get();

        Notification::send($organizers, $notification);
    }

    private function url(SubjectSnapshot $subject): string
    {
        return route('surveys.subject', [$subject->sourceType, $subject->subjectId]);
    }
}
