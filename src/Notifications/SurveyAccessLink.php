<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The link someone asked for on the verified public link: their own single-use link (or, for
 * an account of the audience, the survey page). Encrypted in the queue, like the invitation.
 */
final class SurveyAccessLink extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly string $url,
        public readonly ?string $closesAt,
        public readonly bool $anonymous,
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
            ->subject(__('surveys::emails.access_link.subject', ['title' => $this->surveyTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line(__('surveys::emails.access_link.intro', ['title' => EscapesMarkdown::escape($this->surveyTitle), 'site' => EscapesMarkdown::escape($this->siteName)]))
            ->line(__('surveys::emails.access_link.personal'))
            ->when($this->closesAt !== null, fn (MailMessage $mail): MailMessage => $mail->line(__('surveys::emails.closes_at', ['date' => $this->closesAt])))
            ->line(__($this->anonymous ? 'surveys::emails.anonymous' : 'surveys::emails.named'))
            ->action(__('surveys::emails.answer'), $this->url)
            ->line(__('surveys::emails.access_link.not_you'));
    }
}
