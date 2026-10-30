<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invites someone to answer a survey, when it opens or as a reminder before it closes.
 * Accounts get the survey page; guests get their own single-use link.
 */
final class SurveyInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly string $url,
        public readonly ?string $closesAt,
        public readonly bool $anonymous,
        public readonly bool $reminder,
        public readonly string $siteName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->reminder ? 'reminder' : 'invitation';
        $title = EscapesMarkdown::escape($this->surveyTitle);

        return (new MailMessage)
            ->subject(__("surveys::emails.{$key}.subject", ['title' => $this->surveyTitle, 'site' => $this->siteName]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__("surveys::emails.{$key}.intro", ['title' => $title, 'site' => EscapesMarkdown::escape($this->siteName)]))
            ->when($this->closesAt !== null, fn (MailMessage $mail): MailMessage => $mail->line(__('surveys::emails.closes_at', ['date' => $this->closesAt])))
            ->line(__($this->anonymous ? 'surveys::emails.anonymous' : 'surveys::emails.named'))
            ->action(__('surveys::emails.answer'), $this->url);
    }
}
