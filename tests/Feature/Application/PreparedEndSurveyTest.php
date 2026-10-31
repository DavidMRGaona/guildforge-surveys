<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Domain\Surveys\Events\SurveySubjectCancelled;
use App\Domain\Surveys\Events\SurveySubjectEnded;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Exceptions\SurveyPreparationUnavailableException;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyOrganizerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Notifications\SurveyReadyToSend;
use Modules\Surveys\Notifications\SurveyScheduled;
use Modules\Surveys\Notifications\SurveyWithdrawnWithSubject;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * An organizer prepares their subject's end survey before the subject ends; on the end it goes
 * out by itself or is offered to them, as they chose, and a cancelled subject drops it.
 */
final class PreparedEndSurveyTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private UserModel $stranger;

    private SurveyTemplate $template;

    private DateTimeImmutable $now;

    private OrganizerSurveyService $service;

    private SurveyRepositoryInterface $surveys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);

        $this->organizer = UserModel::factory()->create();
        $this->stranger = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO(array_values(UserModel::factory()->count(2)->create()->map(static fn (UserModel $player): string => $player->id)->all()));

        $this->template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            'Cuéntanos qué tal fue',
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($this->template);
        $this->configure(['auto_send' => true, 'send_delay_hours' => 12]);

        $this->now = new DateTimeImmutable('2030-01-04T10:00:00+00:00');
        $this->travelTo($this->now);

        $this->service = $this->app->make(OrganizerSurveyService::class);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_an_organizer_prepares_the_end_survey_from_the_template_before_the_end(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertTrue($survey->isAwaitingSubjectEnd());
        $this->assertSame('Valoración de ejemplo', $survey->title());
        $this->assertSame($this->organizer->id, $survey->createdBy());
        $this->assertSame(ExampleThingSource::TYPE.':thing-1', $survey->subjectEndKey());
        $this->assertTrue($survey->sendsOnEnd());
        $this->assertSame(12, $survey->sendDelayHours());
        $this->assertFalse($survey->hasUnreviewedChanges());
        $this->assertSame(0, SurveyRecipientModel::query()->count());
        Notification::assertNothingSent();
    }

    public function test_the_type_without_automatic_sending_prepares_a_one_click_survey(): void
    {
        $this->configure(['auto_send' => false]);

        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        $this->assertFalse($survey->sendsOnEnd());
    }

    public function test_preparing_twice_gives_the_same_survey(): void
    {
        $first = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $second = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        $this->assertSame($first->id()->value, $second->id()->value);
        $this->assertSame(1, SurveyModel::query()->count());
    }

    public function test_preparing_again_takes_back_a_dismissed_survey(): void
    {
        $first = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->service->dismiss($first->id(), $this->organizer->id);

        $again = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        $this->assertSame($first->id()->value, $again->id()->value);
        $this->assertNull($again->dismissedAt());
    }

    public function test_only_who_manages_the_subject_prepares(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->stranger->id);
    }

    public function test_an_unknown_subject_cannot_be_prepared(): void
    {
        $this->expectException(SubjectNotFoundException::class);

        $this->service->prepare(ExampleThingSource::TYPE, 'thing-404', $this->organizer->id);
    }

    public function test_without_a_template_for_its_type_nothing_is_prepared(): void
    {
        config()->set('modules.settings.surveys.subject_types', []);

        try {
            $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
            $this->fail('A subject type without a template was prepared.');
        } catch (SurveyPreparationUnavailableException $exception) {
            $this->assertSame('no_template', $exception->reason);
        }

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_a_cancelled_subject_cannot_be_prepared(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        try {
            $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
            $this->fail('A cancelled subject was prepared.');
        } catch (SurveyPreparationUnavailableException $exception) {
            $this->assertSame('cancelled', $exception->reason);
        }
    }

    public function test_a_subject_that_already_ended_gets_a_survey_ready_to_send(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Ended;

        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        $this->assertFalse($survey->isAwaitingSubjectEnd());
        $this->assertEquals($this->now, $survey->subjectEndedAt());
        $this->assertSame(SurveyStatus::Draft, $survey->status());
    }

    public function test_a_prepared_survey_cannot_be_sent_by_hand_before_the_end(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        $this->expectException(InvalidSurveyStateException::class);

        $this->service->send($survey->id(), $this->organizer->id);
    }

    public function test_on_the_end_a_prepared_survey_goes_out_after_its_own_delay(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->choose($survey, sendOnEnd: true, delay: 2);

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        $sent = $this->surveys->findOrFail($survey->id());
        $this->assertSame(1, SurveyModel::query()->count());
        $this->assertEquals(new DateTimeImmutable('2030-01-05T22:00:00+00:00'), $sent->scheduledSendAt());
        $this->assertEquals(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), $sent->subjectEndedAt());
        Notification::assertSentTo($this->organizer, SurveyScheduled::class);
    }

    public function test_on_the_end_a_prepared_survey_with_no_delay_opens_at_once(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->choose($survey, sendOnEnd: true, delay: 0);

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
    }

    public function test_changed_content_still_goes_to_review_on_the_end(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->choose($survey, sendOnEnd: true, delay: 0);
        $changed = $this->surveys->findOrFail($survey->id());
        $changed->markUnreviewedChanges($this->organizer->id);
        $this->surveys->save($changed);

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        $pending = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::PendingReview, $pending->status());
        $this->assertSame($this->organizer->id, $pending->submittedBy());
        Notification::assertNotSentTo(UserModel::query()->whereKeyNot($this->organizer->id)->get(), SurveyInvitation::class);
    }

    public function test_on_the_end_a_one_click_survey_is_offered_to_its_organizers(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->choose($survey, sendOnEnd: false, delay: 0);

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        $offered = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Draft, $offered->status());
        $this->assertFalse($offered->isAwaitingSubjectEnd());
        $this->assertNull($offered->scheduledSendAt());
        Notification::assertSentTo($this->organizer, SurveyReadyToSend::class);
    }

    public function test_the_end_refreshes_the_subject_and_its_organizers(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $newDirector = UserModel::factory()->create();
        ExampleThingSource::$title = 'Partida renombrada';
        ExampleThingSource::$organizerIds = [$newDirector->id];
        ExampleThingSource::$managers = [$newDirector->id];
        ExampleThingSource::$status = SurveySubjectStatus::Ended;

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        $refreshed = $this->surveys->findOrFail($survey->id());
        $this->assertSame('Partida renombrada', $refreshed->subject()?->title);
        $this->assertSame([$newDirector->id], SurveyOrganizerModel::query()->where('survey_id', $survey->id()->value)->pluck('user_id')->all());
        Notification::assertSentTo($newDirector, SurveyScheduled::class);
        Notification::assertNotSentTo($this->organizer, SurveyScheduled::class);
    }

    public function test_a_repeated_end_sends_once(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->choose($survey, sendOnEnd: true, delay: 0);

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');
        $this->end(new DateTimeImmutable('2030-01-05T20:30:00+00:00'), now: '2030-01-05T20:30:00+00:00');

        $this->assertSame(1, SurveyModel::query()->count());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
    }

    public function test_a_dismissed_prepared_survey_is_left_alone_on_the_end(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->service->dismiss($survey->id(), $this->organizer->id);

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        $dismissed = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Draft, $dismissed->status());
        $this->assertNull($dismissed->scheduledSendAt());
        $this->assertSame(1, SurveyModel::query()->count());
        Notification::assertNothingSent();
    }

    public function test_a_prepared_survey_still_goes_out_when_the_template_is_gone(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->choose($survey, sendOnEnd: true, delay: 0);
        config()->set('modules.settings.surveys.subject_types', []);

        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_a_cancelled_subject_drops_its_unopened_surveys_and_tells_the_organizers(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        event(new SurveySubjectCancelled(ExampleThingSource::TYPE, 'thing-1', $this->now));

        $dropped = $this->surveys->findOrFail($survey->id());
        $this->assertEquals($this->now, $dropped->dismissedAt());
        Notification::assertSentTo($this->organizer, SurveyWithdrawnWithSubject::class, fn (SurveyWithdrawnWithSubject $mail): bool => $mail->subjectTitle === 'Partida de ejemplo'
            && $mail->surveyTitle === 'Valoración de ejemplo');
        Notification::assertNotSentTo($this->stranger, SurveyWithdrawnWithSubject::class);
    }

    public function test_an_open_survey_carries_on_when_its_subject_is_cancelled(): void
    {
        $survey = $this->service->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);
        $this->choose($survey, sendOnEnd: true, delay: 0);
        $this->end(new DateTimeImmutable('2030-01-05T20:00:00+00:00'), now: '2030-01-05T20:00:00+00:00');

        event(new SurveySubjectCancelled(ExampleThingSource::TYPE, 'thing-1', new DateTimeImmutable('2030-01-05T21:00:00+00:00')));

        $open = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Open, $open->status());
        $this->assertNull($open->dismissedAt());
        Notification::assertNotSentTo($this->organizer, SurveyWithdrawnWithSubject::class);
    }

    public function test_cancelling_a_subject_without_surveys_does_nothing(): void
    {
        event(new SurveySubjectCancelled(ExampleThingSource::TYPE, 'thing-1', $this->now));

        $this->assertSame(0, SurveyModel::query()->count());
        Notification::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $type
     */
    private function configure(array $type): void
    {
        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => array_merge(['template_id' => $this->template->id()->value], $type),
        ]);
    }

    private function choose(Survey $survey, bool $sendOnEnd, int $delay): void
    {
        $prepared = $this->surveys->findOrFail($survey->id());
        $prepared->chooseEndSending($sendOnEnd, $delay);
        $this->surveys->save($prepared);
    }

    private function end(DateTimeImmutable $endedAt, string $now): void
    {
        $this->travelTo(new DateTimeImmutable($now));
        event(new SurveySubjectEnded(ExampleThingSource::TYPE, 'thing-1', $endedAt));
    }
}
