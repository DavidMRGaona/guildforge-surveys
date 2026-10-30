<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Services\TimezoneServiceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\DTOs\SurveyGuestDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Surveys\Application\DTOs\OpenedSurveyDTO;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Factories\SubjectSnapshotFactory;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\InvalidSubmissionException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\Exceptions\SurveyNotAcceptingResponsesException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyAnswerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

final class SubmissionServiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SubmissionService $submissions;

    private SurveyLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->submissions = $this->app->make(SubmissionService::class);
        $this->lifecycle = $this->app->make(SurveyLifecycleService::class);
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
    }

    public function test_an_anonymous_response_keeps_no_trace_of_the_person(): void
    {
        $user = UserModel::factory()->create();
        [$id] = $this->openPoll(anonymous: true, users: [$user->id]);

        $this->submissions->submit($id, Respondent::account($user->id), ['q-1' => 'o-2']);

        $response = SurveyResponseModel::query()->sole();
        $this->assertNull($response->user_id);
        $this->assertNull($response->guest_name);
        $this->assertSame($this->app->make(TimezoneServiceInterface::class)->today()->format('Y-m-d'), $response->submitted_on->format('Y-m-d'));
        $this->assertSame('o-2', SurveyAnswerModel::query()->sole()->option_id);
        $participation = SurveyParticipationModel::query()->sole();
        $this->assertSame($user->id, $participation->user_id);
        $this->assertNotContains('response_id', Schema::getColumnListing('surveys_participations'));
        $this->assertSame('4', $response->id[14]);
    }

    public function test_a_second_response_from_the_same_person_is_refused_and_leaves_nothing(): void
    {
        $user = UserModel::factory()->create();
        [$id] = $this->openPoll(anonymous: true, users: [$user->id]);
        $this->submissions->submit($id, Respondent::account($user->id), ['q-1' => 'o-1']);

        try {
            $this->submissions->submit($id, Respondent::account($user->id), ['q-1' => 'o-2']);
            $this->fail('A second response must be refused.');
        } catch (AlreadyAnsweredException) {
        }

        $this->assertSame(1, SurveyResponseModel::query()->count());
        $this->assertSame(1, SurveyAnswerModel::query()->count());
    }

    public function test_only_recipients_answer_with_their_account(): void
    {
        [$id] = $this->openPoll(anonymous: true, users: [UserModel::factory()->create()->id]);

        $this->expectException(NotInvitedException::class);

        $this->submissions->submit($id, Respondent::account(UserModel::factory()->create()->id), ['q-1' => 'o-1']);
    }

    public function test_a_guest_answers_once_with_their_link_and_a_named_survey_keeps_their_name(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: false, guests: [new SurveyGuestDTO('guest-1', 'Invitada de ejemplo', 'invitada@example.test')]);
        $token = $opened->guestTokens['guest-1'];

        $this->submissions->submit($id, Respondent::guest($token), ['q-1' => 'o-1']);

        $this->assertSame('Invitada de ejemplo', SurveyResponseModel::query()->sole()->guest_name);
        $this->assertSame(hash('sha256', $token), SurveyParticipationModel::query()->sole()->token_hash);
        $this->expectException(AlreadyAnsweredException::class);
        $this->submissions->submit($id, Respondent::guest($token), ['q-1' => 'o-2']);
    }

    public function test_a_guest_link_only_works_for_its_own_survey(): void
    {
        [, $opened] = $this->openPoll(anonymous: true, guests: [new SurveyGuestDTO('guest-1', 'Invitada', 'invitada@example.test')]);
        [$other] = $this->openPoll(anonymous: true, users: [UserModel::factory()->create()->id]);

        $this->expectException(NotInvitedException::class);

        $this->submissions->submit($other, Respondent::guest($opened->guestTokens['guest-1']), ['q-1' => 'o-1']);
    }

    public function test_a_named_account_response_keeps_the_account(): void
    {
        $user = UserModel::factory()->create();
        [$id] = $this->openPoll(anonymous: false, users: [$user->id]);

        $this->submissions->submit($id, Respondent::account($user->id), ['q-1' => 'o-1']);

        $this->assertSame($user->id, SurveyResponseModel::query()->sole()->user_id);
    }

    public function test_nothing_is_accepted_after_the_closing_date(): void
    {
        $user = UserModel::factory()->create();
        [$id] = $this->openPoll(anonymous: true, users: [$user->id], closesAt: now()->addHour()->toDateTimeImmutable());
        $this->travel(2)->hours();

        $this->expectException(SurveyNotAcceptingResponsesException::class);

        $this->submissions->submit($id, Respondent::account($user->id), ['q-1' => 'o-1']);
    }

    public function test_an_invalid_submission_is_refused_without_storing_anything(): void
    {
        $user = UserModel::factory()->create();
        [$id] = $this->openPoll(anonymous: true, users: [$user->id]);

        try {
            $this->submissions->submit($id, Respondent::account($user->id), ['q-1' => 'o-9']);
            $this->fail('An invalid option must be refused.');
        } catch (InvalidSubmissionException $exception) {
            $this->assertSame(['q-1' => 'invalid_option'], $exception->errors);
        }

        $this->assertSame(0, SurveyResponseModel::query()->count());
        $this->assertSame(0, SurveyParticipationModel::query()->count());
    }

    public function test_the_public_link_accepts_anyone_with_its_token_without_participation(): void
    {
        [$id, $opened] = $this->openPoll(anonymous: true, users: [], publicLink: true);
        $this->assertIsString($opened->publicToken);

        $this->submissions->submit($id, Respondent::publicLink($opened->publicToken), ['q-1' => 'o-1']);
        $this->submissions->submit($id, Respondent::publicLink($opened->publicToken), ['q-1' => 'o-2']);

        $this->assertSame(2, SurveyResponseModel::query()->count());
        $this->assertSame(0, SurveyParticipationModel::query()->count());
        $this->expectException(NotInvitedException::class);
        $this->submissions->submit($id, Respondent::publicLink('not-the-token'), ['q-1' => 'o-1']);
    }

    /**
     * @param  list<string>  $users
     * @param  list<SurveyGuestDTO>  $guests
     * @return array{SurveyId, OpenedSurveyDTO}
     */
    private function openPoll(bool $anonymous, array $users = [], array $guests = [], ?DateTimeImmutable $closesAt = null, bool $publicLink = false): array
    {
        ExampleThingSource::$audience = new SurveyAudienceDTO($users, $guests);
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Poll,
            title: 'Sondeo de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [[
                'id' => 'q-1', 'type' => 'single_choice', 'title' => '¿Qué día?', 'required' => true,
                'options' => [['id' => 'o-1', 'label' => 'Viernes'], ['id' => 'o-2', 'label' => 'Sábado']],
            ]]]]]),
            anonymous: $anonymous,
            results: ResultsSettings::defaultsFor(SurveyKind::Poll),
            audienceType: AudienceType::Subject,
            publicLinkEnabled: $publicLink,
            subject: SubjectSnapshotFactory::fromDto((new ExampleThingSource)->find('thing-1') ?? throw new LogicException('The example thing exists.')),
            closesAt: $closesAt ?? now()->addDays(7)->toDateTimeImmutable(),
        );
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return [$survey->id(), $this->lifecycle->open($survey->id())];
    }
}
