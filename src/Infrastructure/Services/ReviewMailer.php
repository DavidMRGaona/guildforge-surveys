<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Services\SiteBrandingServiceInterface;
use App\Infrastructure\Authorization\Services\PermissionHolderFinder;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\ReviewNotifierInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Notifications\SurveyReviewApproved;
use Modules\Surveys\Notifications\SurveyReviewRejected;
use Modules\Surveys\Notifications\SurveySubmittedForReview;

/**
 * Moderators approve in the panel: they need panel access as well as surveys.moderate. The
 * decision goes to whoever sent the survey to review, if their account is still active.
 */
final readonly class ReviewMailer implements ReviewNotifierInterface
{
    private const string MODERATE = 'surveys:surveys.moderate';

    public function __construct(
        private PermissionHolderFinder $holders,
        private SiteBrandingServiceInterface $branding,
    ) {}

    public function submittedForReview(Survey $survey): void
    {
        Notification::send(
            $this->holders->usersWhoCan('admin.access', self::MODERATE),
            new SurveySubmittedForReview($survey->title(), $survey->subject()?->title, $survey->id()->value, $this->branding->siteName()),
        );
    }

    public function approved(Survey $survey): void
    {
        $this->toSender($survey, new SurveyReviewApproved($survey->title(), $this->url($survey), $this->branding->siteName()));
    }

    public function rejected(Survey $survey): void
    {
        $this->toSender($survey, new SurveyReviewRejected(
            $survey->title(),
            (string) $survey->rejectionReason(),
            $this->url($survey),
            $this->branding->siteName(),
        ));
    }

    private function toSender(Survey $survey, BaseNotification $notification): void
    {
        $sender = $survey->submittedBy() === null ? null : UserModel::query()->whereKey($survey->submittedBy())->whereNull('anonymized_at')->first();

        if ($sender instanceof UserModel) {
            $sender->notify($notification);
        }
    }

    /**
     * Where the organizer manages the survey: its subject's page.
     */
    private function url(Survey $survey): string
    {
        $subject = $survey->subject();

        return $subject instanceof SubjectSnapshot ? route('surveys.subject', [$subject->sourceType, $subject->subjectId]) : url('/');
    }
}
