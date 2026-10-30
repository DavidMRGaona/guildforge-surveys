<?php

declare(strict_types=1);

namespace Modules\Surveys\Console\Commands;

use Illuminate\Console\Command;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;

final class CloseDueSurveysCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'surveys:close-due';

    /**
     * @var string
     */
    protected $description = 'Close open surveys whose closing date has passed and forget their guests\' email addresses';

    public function handle(SurveyRepositoryInterface $surveys, SurveyLifecycleService $lifecycle): int
    {
        $now = now()->toDateTimeImmutable();
        $closed = 0;

        foreach ($surveys->findDueToClose($now) as $survey) {
            try {
                $lifecycle->close($survey->id(), $now);
                $closed++;
            } catch (InvalidSurveyStateException) {
                // Closed meanwhile from the panel: nothing left to do
            }
        }

        $this->info("Closed {$closed} survey(s).");

        return self::SUCCESS;
    }
}
