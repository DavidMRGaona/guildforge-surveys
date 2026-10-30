<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class SurveyInvitation extends Notification
{
    use Queueable;

    public function __construct(
        // TODO: Add notification-specific readonly properties
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('surveys::emails.survey_invitation.subject'))
            ->greeting(__('surveys::emails.survey_invitation.greeting'))
            ->line(__('surveys::emails.survey_invitation.intro'));
    }
}
