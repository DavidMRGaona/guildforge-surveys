<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\Mail\DTOs\MailSubjectDTO;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationDTO;
use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Surveys\Filament\Resources\SurveyResource;

/**
 * Tells a moderator that a survey changed outside the panel waits for their review. The link to
 * the panel is built when the mail is sent, where the panel is registered.
 */
final class SurveySubmittedForReview extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly ?string $subjectTitle,
        public readonly string $surveyId,
        public readonly string $siteName,
    ) {}

    public function mailCategory(): string
    {
        return SurveyMailCategories::MODERATION;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', HasSiteNotification::CHANNEL];
    }

    public function mailSubject(): ?MailSubjectDTO
    {
        return SurveyMailSubjects::survey($this->surveyId, $this->surveyTitle);
    }

    public function toSiteNotification(object $notifiable): SiteNotificationDTO
    {
        return new SiteNotificationDTO(
            title: __('surveys::notices.submitted_for_review', ['title' => $this->surveyTitle]),
            url: SurveyResource::getUrl('view', ['record' => $this->surveyId], isAbsolute: false),
            body: $this->subjectTitle === null ? null : (string) __('surveys::emails.submitted_for_review.subject_line', ['subject' => $this->subjectTitle]),
            icon: 'clipboard-check',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('surveys::emails.submitted_for_review.subject', ['title' => $this->surveyTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__('surveys::emails.submitted_for_review.intro', [
                'title' => EscapesMarkdown::escape($this->surveyTitle),
                'site' => EscapesMarkdown::escape($this->siteName),
            ]))
            ->when($this->subjectTitle !== null, fn (MailMessage $mail): MailMessage => $mail->line(__('surveys::emails.submitted_for_review.subject_line', [
                'subject' => EscapesMarkdown::escape((string) $this->subjectTitle),
            ])))
            ->action(__('surveys::emails.submitted_for_review.action'), SurveyResource::getUrl('view', ['record' => $this->surveyId]));
    }
}
