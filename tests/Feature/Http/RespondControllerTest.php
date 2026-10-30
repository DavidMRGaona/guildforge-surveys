<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

final class RespondControllerTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->player = UserModel::factory()->create();
    }

    public function test_a_recipient_sees_the_open_survey(): void
    {
        [$id] = $this->openSurvey();

        $this->actingAs($this->player)->get(route('surveys.respond', $id->value))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Respond', false)
                ->where('state', 'open')
                ->where('survey.title', 'Valora la partida')
                ->where('survey.anonymous', true)
                ->where('definition.sections.0.questions.0.id', 'q-1')
                ->where('submitUrl', route('surveys.respond.store', $id->value)));
    }

    public function test_someone_else_is_told_they_are_not_invited(): void
    {
        [$id] = $this->openSurvey();

        $this->actingAs(UserModel::factory()->create())->get(route('surveys.respond', $id->value))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('state', 'not_invited')
                ->missing('survey')
                ->missing('definition')
                ->where('seo.title', fn (string $title): bool => ! str_contains($title, 'Valora la partida')));
    }

    public function test_answering_redirects_back_and_then_shows_it_answered(): void
    {
        [$id] = $this->openSurvey();

        $this->actingAs($this->player)
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-1' => 'o-2']])
            ->assertRedirect(route('surveys.respond', $id->value))
            ->assertSessionHas('surveys.result', 'submitted');

        $this->assertSame(1, SurveyResponseModel::query()->count());
        $this->actingAs($this->player)->get(route('surveys.respond', $id->value))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'answered'));
        $this->actingAs($this->player)
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-1' => 'o-1']])
            ->assertSessionHasErrors('survey');
        $this->assertSame(1, SurveyResponseModel::query()->count());
    }

    public function test_an_invalid_answer_comes_back_on_its_question(): void
    {
        [$id] = $this->openSurvey();

        $this->actingAs($this->player)
            ->from(route('surveys.respond', $id->value))
            ->post(route('surveys.respond.store', $id->value), ['answers' => ['q-1' => 'o-9']])
            ->assertSessionHasErrors(['answers.q-1' => __('surveys::messages.submission_errors.invalid_option')]);

        $this->assertSame(0, SurveyResponseModel::query()->count());
    }

    public function test_a_guest_answers_with_their_link(): void
    {
        [$id, $opened] = $this->openSurvey(guest: true);
        $token = $opened->guestTokens['guest-1'];

        $this->get(route('surveys.guest', [$id->value, $token]))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'open')->where('submitUrl', route('surveys.guest.store', [$id->value, $token])));
        $this->post(route('surveys.guest.store', [$id->value, $token]), ['answers' => ['q-1' => 'o-1']])
            ->assertRedirect(route('surveys.guest', [$id->value, $token]));

        $this->get(route('surveys.guest', [$id->value, $token]))->assertInertia(fn (Assert $page) => $page->where('state', 'answered'));
    }

    public function test_a_guest_link_after_closing_shows_it_closed_and_their_email_is_gone(): void
    {
        [$id, $opened] = $this->openSurvey(guest: true);
        $this->app->make(SurveyLifecycleService::class)->close($id);

        $this->get(route('surveys.guest', [$id->value, $opened->guestTokens['guest-1']]))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'closed'));
        $this->assertNull(SurveyRecipientModel::query()->where('guest_reference', 'guest-1')->sole()->guest_email);
    }

    public function test_a_wrong_token_or_unknown_survey_never_fails_loudly(): void
    {
        [$id] = $this->openSurvey(guest: true);

        $this->get(route('surveys.guest', [$id->value, str_repeat('a', 64)]))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'not_invited'));
        $this->actingAs($this->player)->get(route('surveys.respond', '00000000-0000-4000-8000-000000000000'))
            ->assertInertia(fn (Assert $page) => $page->where('state', 'not_invited')->missing('survey'));
    }

    /**
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openSurvey(bool $guest = false): array
    {
        ExampleThingSource::$audience = new SurveyAudienceDTO(
            [$this->player->id],
            $guest ? [new SurveyGuestDTO('guest-1', 'Invitada de ejemplo', 'invitada@example.test')] : [],
        );
        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $id = $lifecycle->createForSubject(
            ExampleThingSource::TYPE,
            'thing-1',
            'Valora la partida',
            SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [[
                'id' => 'q-1', 'type' => 'single_choice', 'title' => '¿Qué tal?', 'required' => true,
                'options' => [['id' => 'o-1', 'label' => 'Bien'], ['id' => 'o-2', 'label' => 'Muy bien']],
            ]]]]]),
            anonymous: true,
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
        );

        return [$id, $lifecycle->open($id)];
    }
}
