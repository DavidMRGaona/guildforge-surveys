<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a subject's organizers that its survey will not go out: the subject was cancelled
 * before it ended.
 */
final class SurveyWithdrawnWithSubject extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subjectTitle,
        public readonly string $surveyTitle,
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
            ->subject(__('surveys::emails.withdrawn_with_subject.subject', ['title' => $this->subjectTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__('surveys::emails.withdrawn_with_subject.intro', [
                'survey' => EscapesMarkdown::escape($this->surveyTitle),
                'title' => EscapesMarkdown::escape($this->subjectTitle),
                'site' => EscapesMarkdown::escape($this->siteName),
            ]))
            ->line(__('surveys::emails.withdrawn_with_subject.kept'));
    }
}
