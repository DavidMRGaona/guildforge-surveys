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
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Invites someone to answer a survey, when it opens or as a reminder before it closes.
 * Accounts get the survey page; guests get their own single-use link. The queued job is
 * encrypted: a guest's link and address never sit in clear in the queue or failed jobs.
 */
final class SurveyInvitation extends Notification implements HasMailCategory, HasMailSubject, HasSiteNotification, ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $surveyTitle,
        public readonly string $url,
        public readonly ?string $closesAt,
        public readonly bool $anonymous,
        public readonly bool $reminder,
        public readonly string $siteName,
        public readonly ?string $surveyId = null,
    ) {}

    public function mailCategory(): string
    {
        return SurveyMailCategories::INVITATIONS;
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
        // Checked when it is delivered: still open, and the person is still in its audience and has not answered
        $path = $this->surveyId !== null
            && app(SubmissionService::class)->stateFor(SurveyId::fromString($this->surveyId), Respondent::account((string) ($notifiable instanceof Model ? $notifiable->getKey() : ''))) === SubmissionService::STATE_OPEN
            ? SiteNoticePath::of($this->url)
            : null;

        if ($path === null) {
            return null;
        }

        return new SiteNotificationDTO(
            title: __($this->reminder ? 'surveys::notices.reminder' : 'surveys::notices.invitation', ['title' => $this->surveyTitle]),
            url: $path,
            body: $this->closesAt === null ? null : (string) __('surveys::emails.closes_at', ['date' => $this->closesAt]),
            icon: 'vote',
        );
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
