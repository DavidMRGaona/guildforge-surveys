<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Application\Mail\DTOs\MailSubjectDTO;

/**
 * What the module's notices are about, for the core's mutes: one survey. Muting it from a mail
 * or the profile holds back every optional notice about it, on the site and by mail, and
 * deleting the survey forgets them all.
 */
final class SurveyMailSubjects
{
    public const string SURVEY = 'surveys.survey';

    /**
     * Null when the notification was queued without the survey's id (an older queued job).
     */
    public static function survey(?string $surveyId, string $title): ?MailSubjectDTO
    {
        if ($surveyId === null || $surveyId === '') {
            return null;
        }

        return new MailSubjectDTO(self::SURVEY, $surveyId, $title, 'surveys::mail.subjects.survey');
    }
}
