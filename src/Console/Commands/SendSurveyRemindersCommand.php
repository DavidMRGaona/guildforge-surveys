<?php

declare(strict_types=1);

namespace Modules\Surveys\Console\Commands;

use Illuminate\Console\Command;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;

final class SendSurveyRemindersCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'surveys:send-reminders';

    /**
     * @var string
     */
    protected $description = 'Remind the people who have not answered a survey whose reminder time has come';

    public function handle(SurveyRepositoryInterface $surveys, SurveyNotifierInterface $notifier): int
    {
        $now = now()->toDateTimeImmutable();
        $reminded = 0;

        foreach ($surveys->findDueForReminder($now) as $survey) {
            // Only one run sends each survey's reminder
            if ($surveys->claimReminder($survey->id(), $now)) {
                $reminded += $notifier->remindPending($survey->id());
            }
        }

        $this->info("Reminded {$reminded} recipient(s).");

        return self::SUCCESS;
    }
}
