<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

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
final class SurveySubmittedForReview extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly ?string $subjectTitle,
        public readonly string $surveyId,
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
