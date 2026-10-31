<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an organizer when their subject's survey will go out by itself, so they can look at it,
 * send it now or choose not to send it before then.
 */
final class SurveyScheduled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subjectTitle,
        public readonly string $sendsAt,
        public readonly string $url,
        public readonly string $siteName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('surveys::emails.scheduled.subject', ['title' => $this->subjectTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__('surveys::emails.scheduled.intro', [
                'title' => EscapesMarkdown::escape($this->subjectTitle),
                'site' => EscapesMarkdown::escape($this->siteName),
                'date' => $this->sendsAt,
            ]))
            ->line(__('surveys::emails.scheduled.edit'))
            ->action(__('surveys::emails.scheduled.action'), $this->url);
    }
}
