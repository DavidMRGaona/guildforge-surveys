<?php

declare(strict_types=1);

namespace Modules\Surveys\Console\Commands;

use Illuminate\Console\Command;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;

final class SendScheduledSurveysCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'surveys:send-scheduled';

    /**
     * @var string
     */
    protected $description = 'Send the subject surveys whose scheduled time has come, or put them to review when they changed';

    public function handle(SurveyRepositoryInterface $surveys, OrganizerSurveyService $organizerSurveys): int
    {
        $now = now()->toDateTimeImmutable();
        $sent = 0;
        $toReview = 0;

        foreach ($surveys->dueForSending($now) as $id) {
            try {
                $outcome = $organizerSurveys->sendScheduled($id, $now);
            } catch (InvalidSurveyStateException) {
                // Its closing date no longer fits: the organizer or the panel will see it as a draft
                continue;
            }

            match ($outcome) {
                SendOutcome::Opened => $sent++,
                SendOutcome::SentForReview => $toReview++,
                null => null,
            };
        }

        $this->info("Sent {$sent} survey(s), {$toReview} to review.");

        return self::SUCCESS;
    }
}
