<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

interface SurveyRecipientStoreInterface
{
    /**
     * Stores who the survey goes to. A guest's link token is kept encrypted (to send it
     * again in the reminder) and as a hash (to find it).
     *
     * @param  array<string, string>  $guestTokens  guest reference => link token
     */
    public function fix(SurveyId $surveyId, SurveyAudienceDTO $audience, array $guestTokens): void;

    /**
     * Guest email addresses and link tokens are kept only while the survey is open.
     */
    public function forgetGuestEmails(SurveyId $surveyId): void;
}
