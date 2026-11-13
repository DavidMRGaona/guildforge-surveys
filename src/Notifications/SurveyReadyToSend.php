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

/**
 * Tells an organizer that their subject ended and its survey waits for them to send it.
 */
final class SurveyReadyToSend extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subjectTitle,
        public readonly string $url,
        public readonly string $siteName,
        public readonly ?string $surveyId = null,
    ) {}

    public function mailCategory(): string
    {
        return SurveyMailCategories::ORGANIZER_ACTIONS;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', HasSiteNotification::CHANNEL];
    }

    public function mailSubject(): ?MailSubjectDTO
    {
        return SurveyMailSubjects::survey($this->surveyId, $this->subjectTitle);
    }

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $path = SiteNoticePath::of($this->url);
        if ($path === null) {
            return null;
        }

        return new SiteNotificationDTO(
            title: __('surveys::notices.ready_to_send', ['title' => $this->subjectTitle]),
            url: $path,
            icon: 'vote',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('surveys::emails.ready_to_send.subject', ['title' => $this->subjectTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__('surveys::emails.ready_to_send.intro', [
                'title' => EscapesMarkdown::escape($this->subjectTitle),
                'site' => EscapesMarkdown::escape($this->siteName),
            ]))
            ->line(__('surveys::emails.ready_to_send.edit'))
            ->action(__('surveys::emails.ready_to_send.action'), $this->url);
    }
}
