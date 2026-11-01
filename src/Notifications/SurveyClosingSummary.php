<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Notifications\Support\EscapesMarkdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Surveys\Application\DTOs\Summary\ClosingSummaryDTO;
use Modules\Surveys\Application\DTOs\Summary\SummaryQuestionDTO;
use Modules\Surveys\Filament\Resources\SurveyResource;

/**
 * The summary of a survey's results, sent when it closes. It never carries free text, and an
 * anonymous survey below the minimum only says so. The link to the panel is built when the mail
 * is sent, where the panel is registered.
 */
final class SurveyClosingSummary extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ClosingSummaryDTO $summary,
        public readonly string $surveyId,
        public readonly bool $inPanel,
        public readonly string $siteName,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(): string
    {
        return $this->inPanel
            ? SurveyResource::getUrl('results', ['record' => $this->surveyId])
            : route('surveys.manage.results', $this->surveyId);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $summary = $this->summary;
        $title = EscapesMarkdown::escape($summary->surveyTitle);
        $site = EscapesMarkdown::escape($this->siteName);

        $mail = (new MailMessage)
            ->subject(__('surveys::emails.closing_summary.subject', ['title' => $summary->surveyTitle]))
            ->greeting(__('surveys::emails.greeting'))
            ->line($summary->subjectTitle === null
                ? __('surveys::emails.closing_summary.intro', ['title' => $title, 'site' => $site])
                : __('surveys::emails.closing_summary.intro_subject', ['title' => $title, 'site' => $site, 'subject' => EscapesMarkdown::escape($summary->subjectTitle)]));

        if (! $summary->visible) {
            return $mail
                ->line(__('surveys::emails.closing_summary.below_minimum'))
                ->action(__('surveys::emails.closing_summary.action'), $this->url());
        }

        $mail->line($this->responsesLine());

        if ($summary->responses > 0) {
            foreach ($summary->questions as $question) {
                $mail->line($this->questionLine($question));
            }

            if ($summary->otherQuestions > 0) {
                $mail->line(trans_choice('surveys::emails.closing_summary.other_questions', $summary->otherQuestions));
            }
        }

        return $mail->action(__('surveys::emails.closing_summary.action'), $this->url());
    }

    private function responsesLine(): string
    {
        $summary = $this->summary;
        $responses = (int) $summary->responses;

        if ($responses === 0) {
            return __('surveys::emails.closing_summary.no_responses');
        }

        if ($summary->rate === null || ! $summary->audienceSize) {
            return trans_choice('surveys::emails.closing_summary.responses', $responses);
        }

        return trans_choice('surveys::emails.closing_summary.rate', $summary->audienceSize, [
            'responses' => $responses,
            'audience' => $summary->audienceSize,
            'rate' => (int) round($summary->rate * 100),
        ]);
    }

    private function questionLine(SummaryQuestionDTO $question): string
    {
        $title = EscapesMarkdown::escape($question->title);

        if ($question->belowMinimum) {
            return __('surveys::emails.closing_summary.question_below_minimum', ['title' => $title]);
        }

        if ($question->answered === 0) {
            return __('surveys::emails.closing_summary.question_unanswered', ['title' => $title]);
        }

        if ($question->mean !== null) {
            return trans_choice('surveys::emails.closing_summary.question_mean', $question->answered, [
                'title' => $title,
                'mean' => number_format($question->mean, 1, ',', '.'),
                'min' => (int) $question->scaleMin,
                'max' => (int) $question->scaleMax,
                'answered' => $question->answered,
            ]);
        }

        if ($question->availability) {
            return $question->topOptions === []
                ? __('surveys::emails.closing_summary.question_no_slot', ['title' => $title])
                : trans_choice('surveys::emails.closing_summary.question_best_slot', count($question->topOptions), [
                    'title' => $title,
                    'options' => $this->optionList($question->topOptions),
                    'yes' => $question->topCount,
                    'if_needed' => $question->topIfNeeded,
                    'answered' => $question->answered,
                ]);
        }

        return trans_choice('surveys::emails.closing_summary.question_top', count($question->topOptions), [
            'title' => $title,
            'options' => $this->optionList($question->topOptions),
            'count' => $question->topCount,
            'answered' => $question->answered,
        ]);
    }

    /**
     * «A», «B» y «C»
     *
     * @param  list<string|null>  $labels
     */
    private function optionList(array $labels): string
    {
        $quoted = array_map(
            static fn (?string $label): string => '«'.EscapesMarkdown::escape($label ?? __('surveys::emails.closing_summary.other')).'»',
            $labels,
        );
        $last = array_pop($quoted);

        return $quoted === [] ? (string) $last : implode(', ', $quoted).' '.__('surveys::emails.closing_summary.and').' '.$last;
    }
}
