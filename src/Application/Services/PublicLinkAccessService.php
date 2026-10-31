<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Services;

use Illuminate\Support\Facades\RateLimiter;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Application\Contracts\SurveyRecipientStoreInterface;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\EmailNormalizer;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * The verified public link: whoever holds it gives an address and gets a single-use link
 * there, the same one every time, so each address answers once. An audience member gets their
 * own link instead, so nobody answers twice through two doors.
 *
 * Nothing it does is told back: whether the address answered, belongs to someone or went over
 * its limit, the page always says the same.
 */
final readonly class PublicLinkAccessService
{
    /** Links mailed to one address for one survey in a day, so the link is no way to spam it. */
    public const int LINKS_PER_DAY = 3;

    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyRecipientStoreInterface $recipients,
        private SurveyNotifierInterface $notifier,
    ) {}

    public function requestLink(SurveyId $id, string $publicToken, string $email): void
    {
        $survey = $this->surveys->find($id);
        $token = $survey?->publicToken();
        if (! $survey instanceof Survey
            || $token === null
            || ! hash_equals($token, $publicToken)
            || $survey->publicLinkMode() !== PublicLinkMode::VerifiedEmail
            || ! $survey->isAcceptingResponses(now()->toDateTimeImmutable())) {
            return;
        }

        $normalized = EmailNormalizer::normalize($email);
        $reference = $this->reference($id, $normalized);

        $limit = 'surveys-access-link:'.$reference;
        if (RateLimiter::tooManyAttempts($limit, self::LINKS_PER_DAY)) {
            return;
        }
        RateLimiter::hit($limit, 24 * 60 * 60);

        $recipient = $this->recipients->audienceMemberWith($id, $normalized)
            ?? $this->recipients->publicLinkRecipient($id, $reference, trim($email));

        $this->notifier->sendAccessLink($id, $recipient);
    }

    /**
     * A keyed hash of the address, per survey: a plain hash could be reversed by guessing
     * addresses, and the same address leaves nothing to match across surveys.
     */
    private function reference(SurveyId $id, string $normalizedEmail): string
    {
        return 'email:'.hash_hmac('sha256', $id->value.'|'.$normalizedEmail, (string) config('app.key'));
    }
}
