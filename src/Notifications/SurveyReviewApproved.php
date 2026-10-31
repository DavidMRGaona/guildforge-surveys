<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the organizer who sent a survey to review that it was approved and has gone out.
 */
final class SurveyReviewApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
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
            ->subject(__('surveys::emails.review_approved.subject', ['title' => $this->surveyTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__('surveys::emails.review_approved.intro', [
                'title' => EscapesMarkdown::escape($this->surveyTitle),
                'site' => EscapesMarkdown::escape($this->siteName),
            ]))
            ->action(__('surveys::emails.review_approved.action'), $this->url);
    }
}
