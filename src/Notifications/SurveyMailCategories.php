<?php

declare(strict_types=1);

namespace Modules\Surveys\Notifications;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Mail\DTOs\MailCategoryDTO;

/**
 * The module's mail categories. The keys are stored with each person's choice, so they never
 * change; the notifications return them from mailCategory() and the provider registers them.
 */
final class SurveyMailCategories
{
    public const string INVITATIONS = 'surveys.invitations';

    public const string RESULTS = 'surveys.results';

    public const string CHOSEN_DATE = 'surveys.chosen-date';

    public const string ORGANIZER_ACTIONS = 'surveys.organizer-actions';

    public const string ORGANIZER_NOTICES = 'surveys.organizer-notices';

    public const string MODERATION = 'surveys.moderation';

    public const string ACCESS_LINK = 'surveys.access-link';

    private const string SECTION = 'surveys::mail.categories.section';

    /**
     * @return list<MailCategoryDTO>
     */
    public static function all(): array
    {
        return [
            self::optional(self::INVITATIONS, 'invitations'),
            self::optional(self::RESULTS, 'results'),
            self::mandatory(self::CHOSEN_DATE, 'chosen_date'),
            self::mandatory(self::ORGANIZER_ACTIONS, 'organizer_actions'),
            self::optional(self::ORGANIZER_NOTICES, 'organizer_notices'),
            // Only people who can review see it: the mail goes to those with panel access and the permission
            self::optional(
                self::MODERATION,
                'moderation',
                static fn (object $user): bool => app(AuthorizationServiceInterface::class)->can($user, 'admin.access')
                    && app(AuthorizationServiceInterface::class)->can($user, 'surveys:surveys.moderate'),
            ),
            self::mandatory(self::ACCESS_LINK, 'access_link'),
        ];
    }

    private static function optional(string $key, string $name, ?\Closure $audience = null): MailCategoryDTO
    {
        return MailCategoryDTO::optional(
            key: $key,
            section: self::SECTION,
            label: "surveys::mail.categories.{$name}.label",
            description: "surveys::mail.categories.{$name}.description",
            audience: $audience,
        );
    }

    private static function mandatory(string $key, string $name): MailCategoryDTO
    {
        return MailCategoryDTO::mandatory(
            key: $key,
            section: self::SECTION,
            label: "surveys::mail.categories.{$name}.label",
            description: "surveys::mail.categories.{$name}.description",
            reason: "surveys::mail.categories.{$name}.reason",
        );
    }
}
