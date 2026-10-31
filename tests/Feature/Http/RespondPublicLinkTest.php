<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Notifications\SurveyAccessLink;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Tests\Support\Modules\ModuleTestCase;

final class RespondPublicLinkTest extends ModuleTestCase
{
    use AnswersExampleSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->createExamplePeople();
    }

    public function test_the_verified_link_asks_for_an_email_instead_of_the_questions(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: true, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail);
        $token = (string) $opened->publicToken;

        $this->get(route('surveys.public', [$id->value, $token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Respond', false)
                ->where('state', 'verify_email')
                ->where('survey.title', 'Encuesta de ejemplo')
                ->where('requestUrl', route('surveys.public.request', [$id->value, $token]))
                ->missing('definition')
                ->missing('submitUrl'));
    }

    public function test_asking_for_a_link_always_answers_the_same(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: true, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail);
        [$openId, $openOpened] = $this->openPoll(anonymous: true, publicLink: true);
        $requests = [
            [$id->value, (string) $opened->publicToken, 'ana@example.test'],
            [$id->value, (string) $opened->publicToken, 'ana@example.test'],
            [$id->value, (string) $opened->publicToken, $this->people[0]->email],
            [$openId->value, (string) $openOpened->publicToken, 'ana@example.test'],
            [$id->value, str_repeat('a', 64), 'ana@example.test'],
        ];

        foreach ($requests as [$survey, $token, $email]) {
            $this->post(route('surveys.public.request', [$survey, $token]), ['email' => $email])
                ->assertRedirect(route('surveys.public', [$survey, $token]))
                ->assertSessionHas('surveys.result', 'link_sent')
                ->assertSessionHasNoErrors();
        }

        Notification::assertSentOnDemandTimes(SurveyAccessLink::class, 2);
        Notification::assertSentToTimes($this->people[0], SurveyAccessLink::class, 1);
        $this->assertSame(1, SurveyRecipientModel::query()->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)->count());
    }

    public function test_the_address_must_look_like_one(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: true, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail);

        foreach (['no es un correo', '"ana"@example.test', 'ana(comentario)@example.test'] as $email) {
            $this->post(route('surveys.public.request', [$id->value, (string) $opened->publicToken]), ['email' => $email])
                ->assertSessionHasErrors('email');
        }

        Notification::assertNothingSent();
    }

    public function test_asking_for_links_is_limited_per_ip(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: true, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail);
        $url = route('surveys.public.request', [$id->value, (string) $opened->publicToken]);

        foreach (range(1, 5) as $n) {
            $this->post($url, ['email' => "persona{$n}@example.test"])->assertRedirect();
        }

        $this->post($url, ['email' => 'persona6@example.test'])->assertTooManyRequests();
    }

    public function test_an_address_from_the_public_link_never_sees_who_voted(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: false, publicLink: true, publicLinkMode: PublicLinkMode::VerifiedEmail);
        $submissions = $this->app->make(SubmissionService::class);
        $submissions->submit($id, Respondent::account($this->people[0]->id), ['q-1' => 'o-1']);
        $this->post(route('surveys.public.request', [$id->value, (string) $opened->publicToken]), ['email' => 'ana@example.test']);
        $token = (string) SurveyRecipientModel::query()->where('source', SurveyRecipientModel::SOURCE_PUBLIC_LINK)->sole()->token;
        $submissions->submit($id, Respondent::guest($token), ['q-1' => 'o-1']);
        $submissions->submit($id, Respondent::guest($opened->guestTokens['guest-1']), ['q-1' => 'o-2']);

        $this->get(route('surveys.guest', [$id->value, $token]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', 'answered')
                ->where('results.questions.0.buckets.0.count', 2)
                ->where('results.questions.0.buckets.0.voters', []));

        // A guest of the audience still sees them, as before
        $this->get(route('surveys.guest', [$id->value, $opened->guestTokens['guest-1']]))
            ->assertInertia(fn (Assert $page) => $page->where('results.questions.0.buckets.0.voters', ['Persona A']));
    }
}
