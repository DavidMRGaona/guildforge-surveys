<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyInvitationsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $player;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->player = UserModel::factory()->create(['name' => 'Persona de ejemplo']);
        ExampleThingSource::$audience = new SurveyAudienceDTO([$this->player->id], [new SurveyGuestDTO('guest-1', 'Invitada', 'invitada@example.test')]);
    }

    public function test_opening_mails_accounts_and_guests_with_their_own_link(): void
    {
        [$id, $opened] = $this->open('Valora la partida');

        Notification::assertSentTo($this->player, SurveyInvitation::class, fn (SurveyInvitation $mail): bool => $mail->url === route('surveys.respond', $id->value) && ! $mail->reminder);
        Notification::assertSentOnDemand(SurveyInvitation::class, fn (SurveyInvitation $mail, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'invitada@example.test'
            && $mail->url === route('surveys.guest', [$id->value, $opened->guestTokens['guest-1']]));
    }

    public function test_the_mail_escapes_the_survey_title_and_names_the_site(): void
    {
        $this->open('Mesa *especial* [beta]');

        Notification::assertSentTo($this->player, SurveyInvitation::class, function (SurveyInvitation $mail): bool {
            $message = $mail->toMail($this->player);
            $text = implode("\n", $message->introLines);

            return str_contains($text, 'Mesa \*especial\* \[beta\]')
                && str_contains((string) $message->subject, 'Mesa *especial* [beta]')
                && $message->actionUrl === $mail->url;
        });
    }

    public function test_the_queued_mail_is_encrypted_because_it_carries_the_guest_link(): void
    {
        $this->assertContains(ShouldBeEncrypted::class, class_implements(SurveyInvitation::class));
    }

    public function test_sending_again_mails_nobody_twice(): void
    {
        [$id] = $this->open('Valora la partida');

        $this->app->make(SurveyNotifierInterface::class)->notifyOpened($id);

        Notification::assertSentToTimes($this->player, SurveyInvitation::class, 1);
        Notification::assertSentOnDemandTimes(SurveyInvitation::class, 1);
    }

    public function test_an_account_anonymised_before_sending_gets_nothing(): void
    {
        [$id] = $this->open('Valora la partida', notify: false);
        $this->player->update(['anonymized_at' => now()]);

        $this->app->make(SurveyNotifierInterface::class)->notifyOpened($id);

        Notification::assertNotSentTo($this->player, SurveyInvitation::class);
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
