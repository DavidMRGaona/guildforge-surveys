<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Activity;

use App\Application\Activity\Services\ActivityPublisherInterface;
use App\Application\Services\UserServiceInterface;
use App\Domain\Activity\Events\ActivityRecorded;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\EventFake;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Activity\ResponseActivitySource;
use Modules\Surveys\Infrastructure\Jobs\PublishResponseActivityJob;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Support\BuildsActivityResponses;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Activity\FakesActivityEvents;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Everything that changes what the activity source reports is published again, so whoever keeps a
 * record corrects it: a response sent or taken away, the survey stopping or starting to count
 * (paused for review, approved, turned anonymous, sent to another audience), the survey deleted
 * (its responses go with it through a foreign key, without a model event) and an account removed.
 * Something that does not count says nothing.
 */
final class ResponseActivityPublishingTest extends ModuleTestCase
{
    use BuildsActivityResponses;
    use FakesActivityEvents;

    private const string TYPE = ResponseActivitySource::TYPE;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    /**
     * The subjects of this source announced so far. The core's own sources announce too (creating
     * an account publishes `core.account`), so a count of every event would measure the wrong thing.
     *
     * @return list<string>
     */
    private function published(): array
    {
        $fake = Event::getFacadeRoot();
        $this->assertInstanceOf(EventFake::class, $fake, 'Start with fakeActivityEvents().');

        return array_values(array_map(
            static fn (array $arguments): string => $arguments[0]->subjectId,
            $fake->dispatched(ActivityRecorded::class, static fn (ActivityRecorded $event): bool => $event->sourceType === self::TYPE)->all(),
        ));
    }

    private function openSurvey(bool $anonymous, AudienceType $audience = AudienceType::AllUsers): SurveyId
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Poll,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [[
                'id' => 'q-1', 'type' => 'single_choice', 'title' => '¿Qué día?', 'required' => true,
                'options' => [['id' => 'o-1', 'label' => 'Viernes'], ['id' => 'o-2', 'label' => 'Sábado']],
            ]]]]]),
            anonymous: $anonymous,
            results: ResultsSettings::defaultsFor(SurveyKind::Poll),
            audienceType: $audience,
            closesAt: now()->addDays(7)->toDateTimeImmutable(),
        );
        $repository = $this->app->make(SurveyRepositoryInterface::class);
        $repository->save($survey);
        $this->app->make(SurveyLifecycleService::class)->open($survey->id(), notify: false);

        return $survey->id();
    }

    private function answer(SurveyId $id, UserModel $user): void
    {
        $this->app->make(SubmissionService::class)->submit($id, Respondent::account($user->id), ['q-1' => 'o-1']);
    }

    private function responseIdOf(SurveyId $id): string
    {
        return (string) SurveyResponseModel::query()->where('survey_id', $id->value)->value('id');
    }

    /**
     * @return list<string> the ids of the named responses it holds
     */
    private function namedResponses(SurveyModel $survey, int $howMany = 2): array
    {
        $ids = [];
        foreach (range(1, $howMany) as $_) {
            $ids[] = $this->activityResponse($survey, UserModel::factory()->create())->id;
        }

        return $ids;
    }

    public function test_answering_a_named_survey_publishes_the_response_once(): void
    {
        $user = UserModel::factory()->create();
        $id = $this->openSurvey(anonymous: false);
        $this->fakeActivityEvents();

        $this->answer($id, $user);

        $responseId = $this->responseIdOf($id);
        $this->assertNotSame('', $responseId);
        $this->assertActivityPublishedTimes(self::TYPE, $responseId, 1);
        $this->assertCount(1, $this->published());
    }

    public function test_answering_an_anonymous_survey_publishes_nothing_at_all(): void
    {
        $user = UserModel::factory()->create();
        $id = $this->openSurvey(anonymous: true);
        $this->fakeActivityEvents();

        $this->answer($id, $user);

        $this->assertNotSame('', $this->responseIdOf($id), 'The response was stored.');
        $this->assertSame([], $this->published());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function surveysThatDoNotCount(): array
    {
        return [
            'anonymous' => [['anonymous' => true]],
            'sent to association members' => [['audience_type' => 'association_members']],
            'sent to some roles' => [['audience_type' => 'roles']],
            'sent to a subject' => [['audience_type' => 'subject']],
            'draft' => [['status' => 'draft']],
            'waiting for review' => [['status' => 'pending_review']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('surveysThatDoNotCount')]
    public function test_a_response_to_a_survey_that_does_not_count_says_nothing(array $overrides): void
    {
        $counted = $this->activitySurvey();
        $quiet = $this->activitySurvey($overrides);
        $this->fakeActivityEvents();

        $control = $this->activityResponse($counted, UserModel::factory()->create());
        $this->activityResponse($quiet, UserModel::factory()->create());

        $this->assertActivityPublishedTimes(self::TYPE, $control->id, 1);
        $this->assertCount(1, $this->published());
    }

    public function test_a_guest_response_says_nothing(): void
    {
        $survey = $this->activitySurvey();
        $this->fakeActivityEvents();

        $this->activityResponse($survey, null, guestName: 'Invitada de ejemplo');

        $this->assertSame([], $this->published());
    }

    public function test_taking_a_counted_response_away_publishes_it(): void
    {
        $response = $this->activityResponse($this->activitySurvey(), UserModel::factory()->create());
        $this->fakeActivityEvents();

        $response->delete();

        $this->assertActivityPublishedTimes(self::TYPE, $response->id, 1);
        $this->assertCount(1, $this->published());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('surveysThatDoNotCount')]
    public function test_taking_away_a_response_that_never_counted_says_nothing(array $overrides): void
    {
        $response = $this->activityResponse($this->activitySurvey($overrides), UserModel::factory()->create());
        $this->fakeActivityEvents();

        $response->delete();

        $this->assertSame([], $this->published());
    }

    public function test_a_response_moved_to_another_account_or_taken_from_its_account_is_published(): void
    {
        $response = $this->activityResponse($this->activitySurvey(), UserModel::factory()->create());
        $this->fakeActivityEvents();

        $response->update(['user_id' => UserModel::factory()->create()->id]);
        $this->assertActivityPublishedTimes(self::TYPE, $response->id, 1);

        $this->fakeActivityEvents();
        $response->update(['user_id' => null]);
        $this->assertActivityPublishedTimes(self::TYPE, $response->id, 1);

        $this->fakeActivityEvents();
        $response->update(['guest_name' => 'Otra persona']);
        $this->assertSame([], $this->published());
    }

    public function test_a_response_moved_between_surveys_says_something_only_if_either_one_counts(): void
    {
        $user = UserModel::factory()->create();
        $counted = $this->activitySurvey();
        $other = $this->activitySurvey();
        $draft = $this->activitySurvey(['status' => 'draft']);
        $roles = $this->activitySurvey(['audience_type' => 'roles']);
        $response = $this->activityResponse($draft, $user);
        $this->fakeActivityEvents();

        $response->update(['survey_id' => $roles->id]);
        $this->assertSame([], $this->published(), 'Neither survey counts.');

        $response->update(['survey_id' => $counted->id]);
        $this->assertSame([$response->id], $this->published(), 'It counts now.');

        $this->fakeActivityEvents();
        $response->update(['survey_id' => $roles->id]);
        $this->assertSame([$response->id], $this->published(), 'It counted before.');

        $this->fakeActivityEvents();
        $response->update(['survey_id' => $other->id]);
        $this->assertSame([$response->id], $this->published(), 'It counts again.');
    }

    /**
     * Each case changes the survey from the one that counts, which holds two named responses and a
     * guest's: only what counted before or counts now is published, and never the guest's.
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function changesThatMoveASurveyInOrOut(): array
    {
        return [
            'paused for review' => [[], ['status' => 'pending_review']],
            'approved again' => [['status' => 'pending_review'], ['status' => 'open']],
            'sent back to a draft' => [[], ['status' => 'draft']],
            'turned anonymous' => [[], ['anonymous' => true]],
            'sent to another audience' => [[], ['audience_type' => 'association_members']],
            'sent to everybody' => [['audience_type' => 'roles'], ['audience_type' => 'all_users']],
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    #[DataProvider('changesThatMoveASurveyInOrOut')]
    public function test_a_survey_that_starts_or_stops_counting_publishes_its_named_responses(array $before, array $after): void
    {
        $survey = $this->activitySurvey($before);
        $named = $this->namedResponses($survey);
        $guest = $this->activityResponse($survey, null, guestName: 'Invitada de ejemplo');
        $this->fakeActivityEvents();

        $survey->update($after);

        foreach ($named as $id) {
            $this->assertActivityPublishedTimes(self::TYPE, $id, 1);
        }
        $this->assertNotContains($guest->id, $this->published());
        $this->assertCount(2, $this->published());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function changesThatMoveNothing(): array
    {
        return [
            'closing it' => [['status' => 'closed']],
            'renaming it' => [['title' => 'Otro título']],
            'a note to the moderator' => [['rejection_reason' => 'Falta una pregunta']],
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    #[DataProvider('changesThatMoveNothing')]
    public function test_a_change_that_leaves_the_survey_counting_the_same_says_nothing(array $changes): void
    {
        $survey = $this->activitySurvey();
        $this->namedResponses($survey);
        $this->fakeActivityEvents();

        $survey->update($changes);

        $this->assertSame([], $this->published());
    }

    public function test_a_change_to_a_survey_that_never_counted_says_nothing(): void
    {
        $survey = $this->activitySurvey(['anonymous' => true]);
        $this->activityResponse($survey);
        $this->fakeActivityEvents();

        $survey->update(['status' => 'closed', 'title' => 'Otro título']);

        $this->assertSame([], $this->published());
    }

    public function test_a_survey_that_stops_counting_with_no_named_response_says_nothing(): void
    {
        $survey = $this->activitySurvey();
        $this->activityResponse($survey, null, guestName: 'Invitada de ejemplo');
        $this->fakeActivityEvents();

        $survey->update(['status' => 'pending_review']);

        $this->assertSame([], $this->published());
    }

    public function test_the_whole_review_cycle_through_the_domain_publishes_each_time_it_flips(): void
    {
        $user = UserModel::factory()->create();
        $id = $this->openSurvey(anonymous: false);
        $this->answer($id, $user);
        $responseId = $this->responseIdOf($id);
        $repository = $this->app->make(SurveyRepositoryInterface::class);
        $this->fakeActivityEvents();

        $moderator = UserModel::factory()->create()->id;
        $survey = $repository->findOrFail($id);
        $survey->submitForReview($moderator, now()->toDateTimeImmutable());
        $repository->save($survey);
        $this->assertActivityPublishedTimes(self::TYPE, $responseId, 1);

        $survey = $repository->findOrFail($id);
        $survey->approve($moderator, now()->toDateTimeImmutable());
        $repository->save($survey);
        $this->assertActivityPublishedTimes(self::TYPE, $responseId, 2);
    }

    public function test_a_survey_with_many_responses_is_published_in_batches_of_a_fixed_size(): void
    {
        $survey = $this->activitySurvey();
        $user = UserModel::factory()->create();
        $rows = [];
        foreach (range(1, PublishResponseActivityJob::BATCH + 1) as $_) {
            $rows[] = ['id' => (string) Str::uuid(), 'survey_id' => $survey->id, 'submitted_on' => '2030-03-04', 'user_id' => $user->id, 'verified' => true];
        }
        SurveyResponseModel::query()->insert($rows);
        Queue::fake([PublishResponseActivityJob::class]);

        $survey->update(['status' => 'pending_review']);

        Queue::assertPushed(PublishResponseActivityJob::class, 2);
        Queue::assertPushed(PublishResponseActivityJob::class, static fn (PublishResponseActivityJob $job): bool => count($job->responseIds) === PublishResponseActivityJob::BATCH);
        Queue::assertPushed(PublishResponseActivityJob::class, static fn (PublishResponseActivityJob $job): bool => count($job->responseIds) === 1);
    }

    public function test_deleting_a_counted_survey_publishes_each_named_response_once_it_is_gone(): void
    {
        $survey = $this->activitySurvey();
        $named = $this->namedResponses($survey);
        $this->activityResponse($survey, null, guestName: 'Invitada de ejemplo');
        $seen = [];
        Event::listen(ActivityRecorded::class, function (ActivityRecorded $event) use (&$seen, $survey): void {
            if ($event->sourceType !== self::TYPE) {
                return;
            }

            $seen[$event->subjectId] = [
                'survey gone' => ! SurveyModel::query()->whereKey($survey->id)->exists(),
                'response gone' => ! SurveyResponseModel::query()->whereKey($event->subjectId)->exists(),
                'facts' => $this->app->make(ResponseActivitySource::class)->facts($event->subjectId),
            ];
        });
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $this->app->make(SurveyRepositoryInterface::class)->delete(SurveyId::fromString($survey->id));

        $this->assertEqualsCanonicalizing($named, array_keys($seen));
        foreach ($seen as $id => $state) {
            $this->assertTrue($state['survey gone'], "The survey was still there when {$id} was announced.");
            $this->assertTrue($state['response gone'], "{$id} was still there when it was announced.");
            $this->assertSame([], $state['facts']);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('surveysThatDoNotCount')]
    public function test_deleting_a_survey_that_never_counted_says_nothing(array $overrides): void
    {
        $survey = $this->activitySurvey($overrides);
        $this->namedResponses($survey);
        $this->fakeActivityEvents();

        $survey->delete();

        $this->assertSame([], $this->published());
    }

    public function test_removing_an_account_for_good_publishes_its_counted_responses_once_it_is_gone(): void
    {
        $leaving = UserModel::factory()->create();
        $staying = UserModel::factory()->create();
        $mine = $this->activityResponse($this->activitySurvey(), $leaving);
        $theirs = $this->activityResponse($this->activitySurvey(), $staying);
        $quiet = $this->activityResponse($this->activitySurvey(['audience_type' => 'roles']), $leaving);
        $seen = [];
        Event::listen(ActivityRecorded::class, function (ActivityRecorded $event) use (&$seen, $leaving): void {
            if ($event->sourceType !== self::TYPE) {
                return;
            }

            $seen[$event->subjectId] = [
                'account gone' => ! UserModel::withTrashed()->whereKey($leaving->id)->exists(),
                'facts' => $this->app->make(ResponseActivitySource::class)->facts($event->subjectId),
            ];
        });
        $this->app->forgetInstance(ActivityPublisherInterface::class);

        $leaving->forceDelete();

        $this->assertSame([$mine->id], array_keys($seen), 'Only what counted is announced, and nothing of the others.');
        $this->assertTrue($seen[$mine->id]['account gone'], 'It was announced before the account was gone.');
        $this->assertSame([], $seen[$mine->id]['facts']);
        $this->assertNotContains($theirs->id, array_keys($seen));
        $this->assertNotContains($quiet->id, array_keys($seen));
    }

    public function test_anonymizing_an_account_publishes_its_counted_responses(): void
    {
        $user = UserModel::factory()->create();
        $other = UserModel::factory()->create();
        $mine = $this->activityResponse($this->activitySurvey(), $user);
        $theirs = $this->activityResponse($this->activitySurvey(), $other);
        $quiet = $this->activityResponse($this->activitySurvey(['anonymous' => true]));
        $this->fakeActivityEvents();

        $this->app->make(UserServiceInterface::class)->anonymize($user->id);

        $this->assertActivityPublishedTimes(self::TYPE, $mine->id, 1);
        $this->assertNotContains($theirs->id, $this->published());
        $this->assertNotContains($quiet->id, $this->published());
    }

    public function test_deactivating_an_account_publishes_nothing_because_its_responses_keep_counting(): void
    {
        $user = UserModel::factory()->create();
        $this->activityResponse($this->activitySurvey(), $user);
        $this->fakeActivityEvents();

        $user->delete();

        $this->assertSame([], $this->published());
    }

    public function test_a_queue_that_is_down_never_costs_the_save_or_the_deletion(): void
    {
        $survey = $this->activitySurvey();
        $this->namedResponses($survey);
        Bus::swap(new class($this->app) extends Dispatcher
        {
            public function dispatch($command): never
            {
                throw new RuntimeException('The queue is down.');
            }
        });

        // In a transaction of its own the batch is queued when it commits, outside the observer
        DB::transaction(static fn () => $survey->update(['status' => 'pending_review']));
        $this->assertSame('pending_review', SurveyModel::query()->whereKey($survey->id)->value('status'), 'The survey was saved.');

        DB::transaction(static fn () => $survey->delete());
        $this->assertFalse(SurveyModel::query()->whereKey($survey->id)->exists(), 'The survey was deleted.');
    }

    public function test_a_failure_while_publishing_never_costs_the_response(): void
    {
        $user = UserModel::factory()->create();
        $id = $this->openSurvey(anonymous: false);
        $this->app->instance(ActivityPublisherInterface::class, new class implements ActivityPublisherInterface
        {
            public function recorded(string $sourceType, string $subjectId): void
            {
                throw new RuntimeException('The queue is down.');
            }
        });

        $this->answer($id, $user);

        $this->assertNotSame('', $this->responseIdOf($id), 'The response was stored.');
    }
}
