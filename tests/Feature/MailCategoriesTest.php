<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Mail\Contracts\HasMailCategory;
use App\Application\Mail\DTOs\MailCategoryDTO;
use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Domain\Mail\Repositories\MailPreferenceRepositoryInterface;
use App\Domain\ValueObjects\UserId;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Modules\Surveys\Notifications\AvailabilityDateChosen;
use Modules\Surveys\Notifications\SurveyAccessLink;
use Modules\Surveys\Notifications\SurveyClosingSummary;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Notifications\SurveyMailCategories;
use Modules\Surveys\Notifications\SurveyReadyToSend;
use Modules\Surveys\Notifications\SurveyReviewApproved;
use Modules\Surveys\Notifications\SurveyReviewRejected;
use Modules\Surveys\Notifications\SurveyScheduled;
use Modules\Surveys\Notifications\SurveySubmittedForReview;
use Modules\Surveys\Notifications\SurveyWithdrawnWithSubject;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Every mail the module sends to an account belongs to a registered category, so people can
 * choose what they receive and the core filter respects it.
 */
final class MailCategoriesTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            $this->sent[] = $event->notification::class;
        });
    }

    public function test_every_notification_has_a_registered_category(): void
    {
        $registry = $this->app->make(MailCategoryRegistryInterface::class);
        $classes = $this->notificationClasses();

        $this->assertNotEmpty($classes);

        foreach ($classes as $class) {
            $this->assertTrue(is_subclass_of($class, HasMailCategory::class), "{$class} must implement HasMailCategory");

            $notification = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $this->assertInstanceOf(MailCategoryDTO::class, $registry->find($notification->mailCategory()), "{$class} uses an unregistered category");
            $this->assertSame($notification->mailCategory(), $registry->forNotification($notification)?->key);
        }
    }

    public function test_the_categories_follow_the_module_conventions(): void
    {
        $categories = $this->moduleCategories();

        $this->assertCount(count(SurveyMailCategories::all()), $categories);

        foreach ($categories as $category) {
            $this->assertStringStartsWith('surveys.', $category->key);
            $this->assertSame('surveys::mail.categories.section', $category->section);
            $this->assertNotSame($category->label, __($category->label), "{$category->label} is not translated");
            $this->assertNotSame($category->description, __($category->description));
            if ($category->isMandatory()) {
                $this->assertNotNull($category->mandatoryReason);
                $this->assertNotSame($category->mandatoryReason, __((string) $category->mandatoryReason));
            }
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function categoryKinds(): array
    {
        return [
            'invitations' => [SurveyMailCategories::INVITATIONS, false],
            'results' => [SurveyMailCategories::RESULTS, false],
            'chosen date' => [SurveyMailCategories::CHOSEN_DATE, true],
            'organizer actions' => [SurveyMailCategories::ORGANIZER_ACTIONS, true],
            'organizer notices' => [SurveyMailCategories::ORGANIZER_NOTICES, false],
            'moderation' => [SurveyMailCategories::MODERATION, false],
            'access link' => [SurveyMailCategories::ACCESS_LINK, true],
        ];
    }

    #[DataProvider('categoryKinds')]
    public function test_each_category_is_mandatory_or_optional_as_decided(string $key, bool $mandatory): void
    {
        $category = $this->app->make(MailCategoryRegistryInterface::class)->find($key);

        $this->assertInstanceOf(MailCategoryDTO::class, $category);
        $this->assertSame($mandatory, $category->isMandatory());
    }

    public function test_moderation_is_visible_only_to_people_who_can_review(): void
    {
        $category = $this->app->make(MailCategoryRegistryInterface::class)->find(SurveyMailCategories::MODERATION);
        $this->assertInstanceOf(MailCategoryDTO::class, $category);
        $this->assertNotNull($category->audience);

        $user = UserModel::factory()->create();
        $this->assertFalse((bool) ($category->audience)($user));

        $this->mock(AuthorizationServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (object $user, string $permission): bool => in_array($permission, ['admin.access', 'surveys:surveys.moderate'], true),
            );
        });
        $this->assertTrue((bool) ($category->audience)($user));
    }

    public function test_the_other_categories_are_visible_to_everyone(): void
    {
        foreach ($this->moduleCategories() as $category) {
            if ($category->key !== SurveyMailCategories::MODERATION) {
                $this->assertNull($category->audience, "{$category->key} must be visible to everyone");
            }
        }
    }

    /**
     * @return array<string, array{class-string<Notification>, string}>
     */
    public static function optionalNotifications(): array
    {
        return [
            'invitation' => [SurveyInvitation::class, SurveyMailCategories::INVITATIONS],
            'closing summary' => [SurveyClosingSummary::class, SurveyMailCategories::RESULTS],
            'scheduled' => [SurveyScheduled::class, SurveyMailCategories::ORGANIZER_NOTICES],
            'approved' => [SurveyReviewApproved::class, SurveyMailCategories::ORGANIZER_NOTICES],
            'withdrawn' => [SurveyWithdrawnWithSubject::class, SurveyMailCategories::ORGANIZER_NOTICES],
            'submitted for review' => [SurveySubmittedForReview::class, SurveyMailCategories::MODERATION],
        ];
    }

    /**
     * @param  class-string<Notification>  $class
     */
    #[DataProvider('optionalNotifications')]
    public function test_a_disabled_optional_category_is_not_sent(string $class, string $key): void
    {
        $user = UserModel::factory()->create();
        $this->disable($user, $key);

        $user->notify($this->bare($class));

        $this->assertSame([], $this->sent);
    }

    /**
     * @return array<string, array{class-string<Notification>, string}>
     */
    public static function mandatoryNotifications(): array
    {
        return [
            'chosen date' => [AvailabilityDateChosen::class, SurveyMailCategories::CHOSEN_DATE],
            'ready to send' => [SurveyReadyToSend::class, SurveyMailCategories::ORGANIZER_ACTIONS],
            'rejected' => [SurveyReviewRejected::class, SurveyMailCategories::ORGANIZER_ACTIONS],
            'access link' => [SurveyAccessLink::class, SurveyMailCategories::ACCESS_LINK],
        ];
    }

    /**
     * @param  class-string<Notification>  $class
     */
    #[DataProvider('mandatoryNotifications')]
    public function test_a_mandatory_category_is_always_sent(string $class, string $key): void
    {
        $user = UserModel::factory()->create();
        $this->disable($user, $key);

        $user->notify($this->bare($class));

        $this->assertSame([$class], $this->sent);
    }

    public function test_an_enabled_optional_category_is_sent(): void
    {
        UserModel::factory()->create()->notify($this->bare(SurveyInvitation::class));

        $this->assertSame([SurveyInvitation::class], $this->sent);
    }

    /**
     * A notification built without its constructor: the filter only looks at its class.
     *
     * @param  class-string<Notification>  $class
     */
    private function bare(string $class): Notification
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function disable(UserModel $user, string $key): void
    {
        $this->app->make(MailPreferenceRepositoryInterface::class)->save(UserId::fromString($user->id), [$key => false]);
    }

    /**
     * @return list<MailCategoryDTO>
     */
    private function moduleCategories(): array
    {
        return array_values(array_filter(
            $this->app->make(MailCategoryRegistryInterface::class)->all(),
            static fn (MailCategoryDTO $category): bool => str_starts_with($category->key, 'surveys.'),
        ));
    }

    /**
     * @return list<class-string<Notification>>
     */
    private function notificationClasses(): array
    {
        $classes = [];
        foreach (glob(dirname(__DIR__, 2).'/src/Notifications/*.php') ?: [] as $file) {
            $class = 'Modules\\Surveys\\Notifications\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, Notification::class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
