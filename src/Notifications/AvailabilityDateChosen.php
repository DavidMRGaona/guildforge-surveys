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
 * The date fixed for an availability survey, with a link to what it is about when there is one.
 */
final class AvailabilityDateChosen extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly string $date,
        public readonly string $siteName,
        public readonly ?string $subjectTitle = null,
        public readonly ?string $subjectUrl = null,
        public readonly ?string $surveyId = null,
    ) {}

    public function mailCategory(): string
    {
        return SurveyMailCategories::CHOSEN_DATE;
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

    public function toSiteNotification(object $notifiable): ?SiteNotificationDTO
    {
        $path = SiteNoticePath::of($this->subjectUrl) ?? ($this->surveyId === null ? null : SiteNoticePath::of(route('surveys.respond', $this->surveyId)));
        if ($path === null) {
            return null;
        }

        return new SiteNotificationDTO(
            title: __('surveys::notices.date_chosen', ['title' => $this->surveyTitle]),
            url: $path,
            body: mb_ucfirst($this->date),
            icon: 'calendar-days',
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = EscapesMarkdown::escape($this->surveyTitle);
        $site = EscapesMarkdown::escape($this->siteName);

        $mail = (new MailMessage)
            ->subject(__('surveys::emails.date_chosen.subject', ['title' => $this->surveyTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line($this->subjectTitle === null
                ? __('surveys::emails.date_chosen.intro', ['title' => $title, 'site' => $site])
                : __('surveys::emails.date_chosen.intro_subject', ['title' => $title, 'site' => $site, 'subject' => EscapesMarkdown::escape($this->subjectTitle)]))
            ->line(__('surveys::emails.date_chosen.date', ['date' => EscapesMarkdown::escape(mb_ucfirst($this->date))]));

        return $this->subjectUrl === null
            ? $mail
            : $mail->action(__('surveys::emails.date_chosen.action', ['subject' => (string) $this->subjectTitle]), $this->subjectUrl);
    }
}
