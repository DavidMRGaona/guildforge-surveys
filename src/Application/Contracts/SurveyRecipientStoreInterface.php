<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

interface SurveyRecipientStoreInterface
{
    /**
     * Stores who the survey goes to. Guests get their link token only as a hash.
     *
     * @param  array<string, string>  $guestTokenHashes  guest reference => sha256 of the link token
     */
    public function fix(SurveyId $surveyId, SurveyAudienceDTO $audience, array $guestTokenHashes): void;

    /**
     * Guest email addresses are kept only while the survey is open.
     */
    public function forgetGuestEmails(SurveyId $surveyId): void;
}
