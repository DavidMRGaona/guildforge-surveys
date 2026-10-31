<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use Modules\Surveys\Application\DTOs\AccessLinkRecipient;
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
     * Guest email addresses and link tokens are kept only while the survey is open, and so
     * are the references of the addresses that asked for a link.
     */
    public function forgetGuestEmails(SurveyId $surveyId): void;

    /**
     * The audience member (an account or a guest) whose address, normalized, is this one.
     */
    public function audienceMemberWith(SurveyId $surveyId, string $normalizedEmail): ?AccessLinkRecipient;

    /**
     * The single-use link of the address with this reference, created on its first request.
     */
    public function publicLinkRecipient(SurveyId $surveyId, string $reference, string $email): AccessLinkRecipient;
}
