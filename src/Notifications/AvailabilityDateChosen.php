<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The date fixed for an availability survey, with a link to what it is about when there is one.
 */
final class AvailabilityDateChosen extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly string $date,
        public readonly string $siteName,
        public readonly ?string $subjectTitle = null,
        public readonly ?string $subjectUrl = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
            ->line(__('surveys::emails.date_chosen.date', ['date' => EscapesMarkdown::escape($this->date)]));

        return $this->subjectUrl === null
            ? $mail
            : $mail->action(__('surveys::emails.date_chosen.action', ['subject' => (string) $this->subjectTitle]), $this->subjectUrl);
    }
}
