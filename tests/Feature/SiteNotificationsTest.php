<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature;

use App\Application\Mail\Contracts\HasMailSubject;
use App\Application\Mail\DTOs\MailCategoryDTO;
use App\Application\Mail\Services\MailCategoryRegistryInterface;
use App\Application\SiteNotifications\Contracts\HasSiteNotification;
use App\Application\SiteNotifications\DTOs\SiteNotificationItemDTO;
use App\Application\SiteNotifications\Services\SiteNotificationServiceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\DTOs\Summary\ClosingSummaryDTO;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
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
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The bell: the module's notices on the site, for accounts only, checked when delivered.
 */
final class SiteNotificationsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->player = UserModel::factory()->create(['name' => 'Persona de ejemplo']);
        ExampleThingSource::$audience = new SurveyAudienceDTO([$this->player->id], [new SurveyGuestDTO('guest-1', 'Invitada', 'invitada@example.test')]);
    }

    public function test_every_category_shows_on_the_site_except_the_access_link(): void
    {
        $categories = array_filter(
            SurveyMailCategories::all(),
            static fn (MailCategoryDTO $category): bool => $category->key !== SurveyMailCategories::ACCESS_LINK,
        );

        $this->assertCount(6, $categories);

        foreach (SurveyMailCategories::all() as $category) {
            $this->assertSame($category->key !== SurveyMailCategories::ACCESS_LINK, $category->site, $category->key);
            $this->assertTrue($category->mail);
        }

        $registry = $this->app->make(MailCategoryRegistryInterface::class);
        $this->assertTrue($registry->find(SurveyMailCategories::INVITATIONS)?->site);
        $this->assertFalse($registry->find(SurveyMailCategories::ACCESS_LINK)?->site);
    }

    public function test_every_notification_but_the_access_link_goes_through_the_site_channel(): void
    {
        foreach ($this->notifications() as $notification) {
            $this->assertContains(HasSiteNotification::class, class_implements($notification), $notification::class);
            $this->assertContains(HasMailSubject::class, class_implements($notification), $notification::class);
            $this->assertContains(HasSiteNotification::CHANNEL, $notification->via($this->player), $notification::class);
            $this->assertContains('mail', $notification->via($this->player), $notification::class);
        }

        // The access link carries a single-use token and goes to people without account: mail only
        $this->assertNotContains(HasSiteNotification::class, class_implements(SurveyAccessLink::class));
        $this->assertSame(['mail'], (new SurveyAccessLink('Valora', 'https://example.test/encuestas/x/abierta/token', null, true, 'Sitio'))->via($this->player));
    }

    public function test_every_notice_leads_to_a_path_of_the_site_without_any_token(): void
    {
        foreach ($this->notifications() as $notification) {
            $this->player->notify($notification);
        }

        $items = $this->items($this->player);
        $this->assertNotEmpty($items);

        foreach ($items as $item) {
            $this->assertStringStartsWith('/', $item->url);
            $this->assertStringNotContainsString('token', $item->url);
            $this->assertStringNotContainsString('://', $item->url);
        }
    }

    public function test_opening_a_survey_puts_a_notice_in_the_bell_of_the_invited_account(): void
    {
        [$id] = $this->open('Valora la partida');

        $items = $this->items($this->player);

        $this->assertCount(1, $items);
        $this->assertSame(parse_url(route('surveys.respond', $id->value), PHP_URL_PATH), $items[0]->url);
        $this->assertStringContainsString('Valora la partida', $items[0]->title);
        $this->assertSame(SurveyMailCategories::INVITATIONS, $items[0]->category);
    }

    public function test_an_address_without_account_gets_nothing_on_the_site(): void
    {
        [, $opened] = $this->open('Valora la partida');

        // The guest was mailed their own link, and the bell holds only the account's notice
        $this->assertNotEmpty($opened->guestTokens);
        $this->assertSame(1, DB::table('site_notifications')->count());
        $this->assertSame(1, $this->app->make(SiteNotificationServiceInterface::class)->total($this->player));
    }

    public function test_the_invitation_is_not_shown_once_the_survey_closed(): void
    {
        [$id] = $this->open('Valora la partida', notify: false);
        $this->app->make(SurveyLifecycleService::class)->close($id);

        $this->player->notify($this->invitation($id));

        $this->assertSame([], $this->items($this->player));
    }

    public function test_the_invitation_is_not_shown_to_someone_who_is_not_in_the_audience(): void
    {
        [$id] = $this->open('Valora la partida', notify: false);
        $outsider = UserModel::factory()->create();

        $outsider->notify($this->invitation($id));

        $this->assertSame([], $this->items($outsider));
    }

    public function test_the_invitation_is_not_shown_to_someone_who_already_answered(): void
    {
        [$id] = $this->open('Valora la partida', notify: false);
        SurveyParticipationModel::query()->create(['survey_id' => $id->value, 'user_id' => $this->player->id]);

        $this->player->notify($this->invitation($id));

        $this->assertSame([], $this->items($this->player));
    }

    public function test_deleting_a_survey_takes_its_notices_with_it(): void
    {
        [$id] = $this->open('Valora la partida');
        $this->assertSame(1, $this->app->make(SiteNotificationServiceInterface::class)->unreadCount($this->player));

        $this->app->make(SurveyRepositoryInterface::class)->delete($id);

        $this->assertSame(0, $this->app->make(SiteNotificationServiceInterface::class)->unreadCount($this->player));
    }

    public function test_the_mail_subject_is_the_survey(): void
    {
        $notification = new SurveyReviewApproved('Valora', 'https://example.test/x', 'Sitio', 'survey-1');
        $subject = $notification->mailSubject();

        $this->assertNotNull($subject);
        $this->assertSame('surveys.survey', $subject->type);
        $this->assertSame('survey-1', $subject->id);
        $this->assertSame('Valora', $subject->label);
        $this->assertNotSame($subject->typeLabel, __((string) $subject->typeLabel));
        $this->assertNull((new SurveyReviewApproved('Valora', 'https://example.test/x', 'Sitio'))->mailSubject());
    }

    /**
     * @return list<SurveyInvitation|SurveyClosingSummary|AvailabilityDateChosen|SurveyReadyToSend|SurveyScheduled|SurveyReviewApproved|SurveyReviewRejected|SurveyWithdrawnWithSubject|SurveySubmittedForReview>
     */
    private function notifications(): array
    {
        $absolute = route('surveys.subject', ['example-thing', 'thing-1']);
        $survey = '0b1f3c1e-5a53-4c8e-a9a2-6f55f3b8a111';

        return [
            new SurveyInvitation('Valora', $absolute, null, true, false, 'Sitio', $survey),
            new SurveyClosingSummary(new ClosingSummaryDTO('Valora', null, false), $survey, false, 'Sitio'),
            new AvailabilityDateChosen('Valora', 'sábado', 'Sitio', 'Mesa', $absolute, $survey),
            new SurveyReadyToSend('Mesa', $absolute, 'Sitio', $survey),
            new SurveyScheduled('Mesa', '1 de enero', $absolute, 'Sitio', $survey),
            new SurveyReviewApproved('Valora', $absolute, 'Sitio', $survey),
            new SurveyReviewRejected('Valora', 'Motivo', $absolute, 'Sitio', $survey),
            new SurveyWithdrawnWithSubject('Mesa', 'Valora', 'Sitio', $absolute, $survey),
            new SurveySubmittedForReview('Valora', 'Mesa', $survey, 'Sitio'),
        ];
    }

    private function invitation(SurveyId $id): SurveyInvitation
    {
        return new SurveyInvitation('Valora la partida', route('surveys.respond', $id->value), null, true, false, 'Sitio', $id->value);
    }

    /**
     * @return list<SiteNotificationItemDTO>
     */
    private function items(UserModel $user): array
    {
        return $this->app->make(SiteNotificationServiceInterface::class)->latest($user, 20);
    }

    /**
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function open(string $title, bool $notify = true): array
    {
        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $id = $lifecycle->createForSubject(
            ExampleThingSource::TYPE,
            'thing-1',
            $title,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
        );

        return [$id, $lifecycle->open($id, notify: $notify)];
    }
}
