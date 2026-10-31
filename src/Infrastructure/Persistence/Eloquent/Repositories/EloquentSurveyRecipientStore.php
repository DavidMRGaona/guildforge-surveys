<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\DTOs\AccessLinkRecipient;
use Modules\Surveys\Application\Contracts\SurveyRecipientStoreInterface;
use Modules\Surveys\Domain\Services\EmailNormalizer;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;

final class EloquentSurveyRecipientStore implements SurveyRecipientStoreInterface
{
    public function fix(SurveyId $surveyId, SurveyAudienceDTO $audience, array $guestTokens): void
    {
        foreach ($audience->userIds as $userId) {
            SurveyRecipientModel::query()->create(['survey_id' => $surveyId->value, 'user_id' => $userId]);
        }

        foreach ($audience->guests as $guest) {
            SurveyRecipientModel::query()->create([
                'survey_id' => $surveyId->value,
                'guest_reference' => $guest->reference,
                'guest_name' => $guest->displayName,
                'guest_email' => $guest->email,
                'token' => $guestTokens[$guest->reference] ?? null,
                'token_hash' => isset($guestTokens[$guest->reference]) ? hash('sha256', $guestTokens[$guest->reference]) : null,
            ]);
        }
    }

    public function forgetGuestEmails(SurveyId $surveyId): void
    {
        SurveyRecipientModel::query()
            ->where('survey_id', $surveyId->value)
            ->where(static fn ($query) => $query->whereNotNull('guest_email')->orWhereNotNull('token'))
            ->update(['guest_email' => null, 'token' => null]);

        SurveyRecipientModel::query()
            ->where('survey_id', $surveyId->value)
            ->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)
            ->whereNotNull('guest_reference')
            ->update(['guest_reference' => null]);
    }

    public function audienceMemberWith(SurveyId $surveyId, string $normalizedEmail): ?AccessLinkRecipient
    {
        $audience = SurveyRecipientModel::query()
            ->where('survey_id', $surveyId->value)
            ->where('source', SurveyRecipientModel::SOURCE_AUDIENCE);

        // Compared after normalizing both sides, so they cannot be matched in the database
        $accounts = UserModel::query()
            ->whereIn('id', (clone $audience)->whereNotNull('user_id')->select('user_id'))
            ->whereNull('anonymized_at')
            ->select(['id', 'email']);
        foreach ($accounts->lazyById() as $user) {
            if (EmailNormalizer::normalize($user->email) === $normalizedEmail) {
                return AccessLinkRecipient::account($user->id);
            }
        }

        foreach ((clone $audience)->whereNotNull('guest_email')->whereNotNull('token')->lazyById() as $guest) {
            /** @var SurveyRecipientModel $guest */
            if ($guest->guest_email !== null && $guest->token !== null && EmailNormalizer::normalize($guest->guest_email) === $normalizedEmail) {
                return AccessLinkRecipient::withLink($guest->guest_email, $guest->token);
            }
        }

        return null;
    }

    public function publicLinkRecipient(SurveyId $surveyId, string $reference, string $email): AccessLinkRecipient
    {
        $existing = $this->publicLinkRecipientWith($surveyId, $reference);
        if ($existing instanceof AccessLinkRecipient) {
            return $existing;
        }

        $token = bin2hex(random_bytes(32));
        try {
            // A savepoint, so losing the race to the unique reference leaves an outer transaction usable
            DB::transaction(static fn () => SurveyRecipientModel::query()->create([
                'survey_id' => $surveyId->value,
                'source' => SurveyRecipientModel::SOURCE_PUBLIC_LINK,
                'guest_reference' => $reference,
                'guest_email' => $email,
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                // Mailed right away, never by the opening's run
                'notified_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->publicLinkRecipientWith($surveyId, $reference) ?? AccessLinkRecipient::withLink($email, $token);
        }

        return AccessLinkRecipient::withLink($email, $token);
    }

    private function publicLinkRecipientWith(SurveyId $surveyId, string $reference): ?AccessLinkRecipient
    {
        $recipient = SurveyRecipientModel::query()
            ->where('survey_id', $surveyId->value)
            ->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)
            ->where('guest_reference', $reference)
            ->first();

        return $recipient?->guest_email === null || $recipient->token === null ? null : AccessLinkRecipient::withLink($recipient->guest_email, $recipient->token);
    }
}
