<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Contracts\SurveyNotifierInterface;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\PublicLinkAccessService;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Notifications\SurveyAccessLink;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Tests\Support\Modules\ModuleTestCase;

final class PublicLinkAccessServiceTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private PublicLinkAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createExamplePeople();
        $this->access = $this->app->make(PublicLinkAccessService::class);
    }

    public function test_a_verified_address_gets_a_single_use_link(): void
    {
        [$id, $opened] = $this->openVerified();

        $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');

        $recipient = $this->publicRecipient($id);
        $this->assertIsString($recipient->token);
        $this->assertStringStartsWith('email:', (string) $recipient->guest_reference);
        $this->assertStringNotContainsString('ana', (string) $recipient->guest_reference);
        $url = route('surveys.guest', [$id->value, $recipient->token]);
        Notification::assertSentOnDemand(SurveyAccessLink::class, static fn (SurveyAccessLink $mail, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana@example.test' && $mail->url === $url);
        $this->assertSame(4, SurveyModel::query()->findOrFail($id->value)->audience_size, 'Asking for a link never joins the audience.');

        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($id, Respondent::guest($recipient->token), ['q-1' => 'o-1']);
        $this->assertTrue(SurveyResponseModel::query()->sole()->verified);

        $this->expectException(AlreadyAnsweredException::class);
        $submissions->submit($id, Respondent::guest($recipient->token), ['q-1' => 'o-2']);
    }

    public function test_the_same_address_reuses_its_link(): void
    {
        [$id, $opened] = $this->openVerified();

        $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');
        $this->access->requestLink($id, $this->publicToken($opened), '  ANA+otra@Example.test ');

        $recipient = $this->publicRecipient($id);
        $url = route('surveys.guest', [$id->value, $recipient->token]);
        Notification::assertSentOnDemandTimes(SurveyAccessLink::class, 2);
        Notification::assertSentOnDemand(SurveyAccessLink::class, static fn (SurveyAccessLink $mail, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'ana@example.test' && $mail->url === $url);
    }

    public function test_an_address_gets_at_most_three_links_a_day(): void
    {
        [$id, $opened] = $this->openVerified();

        foreach (range(1, 4) as $ignored) {
            $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');
        }

        Notification::assertSentOnDemandTimes(SurveyAccessLink::class, 3);

        $this->travel(25)->hours();
        $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');
        Notification::assertSentOnDemandTimes(SurveyAccessLink::class, 4);
    }

    public function test_an_audience_member_gets_their_own_link(): void
    {
        [$id, $opened] = $this->openVerified();

        $this->access->requestLink($id, $this->publicToken($opened), strtoupper($this->people[0]->email));
        $this->access->requestLink($id, $this->publicToken($opened), 'INVITADA+encuesta@example.test');

        Notification::assertSentTo($this->people[0], SurveyAccessLink::class, static fn (SurveyAccessLink $mail): bool => $mail->url === route('surveys.respond', $id->value));
        Notification::assertSentOnDemand(SurveyAccessLink::class, static fn (SurveyAccessLink $mail, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'invitada@example.test'
            && $mail->url === route('surveys.guest', [$id->value, $opened->guestTokens['guest-1']]));
        $this->assertSame(0, SurveyRecipientModel::query()->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)->count());
    }

    public function test_nothing_is_sent_without_a_verified_link_or_while_closed(): void
    {
        [$open, $openOpened] = $this->openPoll(anonymous: true, publicLink: true);
        $this->access->requestLink($open, $this->publicToken($openOpened), 'ana@example.test');

        [$id, $opened] = $this->openVerified();
        $this->access->requestLink($id, str_repeat('a', 64), 'ana@example.test');

        $this->app->make(SurveyLifecycleService::class)->close($id);
        $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');

        Notification::assertNothingSent();
        $this->assertSame(0, SurveyRecipientModel::query()->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)->count());
    }

    public function test_the_verified_public_link_takes_no_responses_by_itself(): void
    {
        [$id, $opened] = $this->openVerified();

        $this->expectException(NotInvitedException::class);

        $this->app->make(SubmissionService::class)->submit($id, Respondent::publicLink($this->publicToken($opened)), ['q-1' => 'o-1']);
    }

    public function test_an_address_that_asked_for_a_link_gets_no_reminder(): void
    {
        [$id, $opened] = $this->openVerified();
        $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');

        $reminded = $this->app->make(SurveyNotifierInterface::class)->remindPending($id);

        $this->assertSame(4, $reminded);
        $this->assertNull($this->publicRecipient($id)->reminded_at);
    }

    public function test_closing_forgets_the_address_and_its_reference(): void
    {
        [$id, $opened] = $this->openVerified();
        $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');
        $token = (string) $this->publicRecipient($id)->token;
        $raw = DB::table('surveys_recipients')->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)->value('guest_email');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('ana@example.test', $raw);

        $this->app->make(SurveyLifecycleService::class)->close($id);

        $recipient = $this->publicRecipient($id);
        $this->assertNull($recipient->guest_email);
        $this->assertNull($recipient->token);
        $this->assertNull($recipient->guest_reference);
        $this->assertSame(hash('sha256', $token), $recipient->token_hash);
        $this->assertSame(SubmissionService::STATE_CLOSED, $this->app->make(SubmissionService::class)->stateFor($id, Respondent::guest($token)));
    }

    public function test_a_named_survey_keeps_no_name_or_address_for_a_verified_address(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: false, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail);
        $this->access->requestLink($id, $this->publicToken($opened), 'ana@example.test');

        $this->app->make(SubmissionService::class)->submit($id, Respondent::guest((string) $this->publicRecipient($id)->token), ['q-1' => 'o-1']);

        $response = SurveyResponseModel::query()->sole();
        $this->assertNull($response->user_id);
        $this->assertNull($response->guest_name);
    }

    /**
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openVerified(): array
    {
        return $this->openPoll(anonymous: true, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail);
    }

    private function publicToken(OpenedSurveyDTO $opened): string
    {
        $this->assertIsString($opened->publicToken);

        return $opened->publicToken;
    }

    private function publicRecipient(SurveyId $id): SurveyRecipientModel
    {
        return SurveyRecipientModel::query()->where('survey_id', $id->value)->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)->sole();
    }
}
