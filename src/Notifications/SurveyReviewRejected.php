<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the organizer who sent a survey to review that it was rejected, and why, so they can fix it.
 */
final class SurveyReviewRejected extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly string $reason,
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
            ->subject(__('surveys::emails.review_rejected.subject', ['title' => $this->surveyTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__('surveys::emails.review_rejected.intro', [
                'title' => EscapesMarkdown::escape($this->surveyTitle),
                'site' => EscapesMarkdown::escape($this->siteName),
            ]))
            ->line(__('surveys::emails.review_rejected.reason', ['reason' => EscapesMarkdown::escape($this->reason)]))
            ->action(__('surveys::emails.review_rejected.action'), $this->url);
    }
}
