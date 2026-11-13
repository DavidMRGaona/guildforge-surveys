<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Files;

use App\Application\Mail\Services\MailSubjectMuteServiceInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Notifications\SurveyMailSubjects;
use Throwable;

/**
 * Deleting a survey (from the panel, the site or a service) forgets what the core keeps about
 * it, once the deletion is committed: people's mutes of it and every notice about it in their
 * bell, so none leads to a page that is gone. The survey is gone either way: a failure is
 * logged and leaves only those rows behind.
 */
final readonly class ForgetsNoticesWithSurvey
{
    public function __construct(
        private MailSubjectMuteServiceInterface $mutes,
    ) {}

    public function deleted(SurveyModel $survey): void
    {
        $id = (string) $survey->getKey();

        DB::afterCommit(function () use ($id): void {
            try {
                $this->mutes->forgetSubject(SurveyMailSubjects::SURVEY, $id);
            } catch (Throwable $exception) {
                Log::warning('The surveys module could not forget the mutes and notices of a deleted survey.', ['survey' => $id, 'exception' => $exception->getMessage()]);
            }
        });
    }
}
