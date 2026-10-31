<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Console;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Console\Commands\SendScheduledSurveysCommand;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Notifications\SurveySubmittedForReview;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class SendScheduledSurveysCommandTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private SurveyRepositoryInterface $surveys;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        // The module boots after the console kernel resolved its commands
        $this->app->make(Kernel::class)->registerCommand($this->app->make(SendScheduledSurveysCommand::class));

        $this->organizer = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO(UserModel::factory()->count(2)->create()->pluck('id')->all());

        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->now = new DateTimeImmutable('2030-01-06T08:00:00+00:00');
        $this->travelTo($this->now);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_opens_surveys_whose_time_has_come(): void
    {
        $due = $this->scheduled($this->now->modify('-1 minute'));
        $later = $this->scheduled($this->now->modify('+1 hour'));

        $this->artisanCommand('surveys:send-scheduled')->assertSuccessful();

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($due->id())->status());
        $this->assertNull($this->surveys->findOrFail($due->id())->scheduledSendAt());
        $this->assertSame(SurveyStatus::Draft, $this->surveys->findOrFail($later->id())->status());
    }

    public function test_running_twice_opens_once(): void
    {
        $this->scheduled($this->now->modify('-1 minute'));

        $this->artisanCommand('surveys:send-scheduled')->assertSuccessful();
        $this->artisanCommand('surveys:send-scheduled')->assertSuccessful();

        Notification::assertSentTimes(SurveyInvitation::class, 2);
    }

    public function test_changed_content_goes_to_review_at_its_time(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $moderator = $this->moderator();
        $survey = $this->scheduled($this->now->modify('-1 minute'));
        $survey->markUnreviewedChanges($this->organizer->id);
        $this->surveys->save($survey);

        $this->artisanCommand('surveys:send-scheduled')->assertSuccessful();

        $pending = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::PendingReview, $pending->status());
        $this->assertSame($this->organizer->id, $pending->submittedBy());
        $this->assertNull($pending->scheduledSendAt());
        Notification::assertSentTo($moderator, SurveySubmittedForReview::class);
        $this->assertFalse($pending->wasOpened());
    }

    public function test_dismissed_surveys_are_left_alone(): void
    {
        $survey = $this->scheduled($this->now->modify('-1 minute'));
        $survey->dismiss($this->now->modify('-30 minutes'));
        $this->surveys->save($survey);

        $this->artisanCommand('surveys:send-scheduled')->assertSuccessful();

        $this->assertSame(SurveyStatus::Draft, $this->surveys->findOrFail($survey->id())->status());
        Notification::assertNothingSent();
    }

    public function test_sending_now_beats_the_schedule(): void
    {
        $survey = $this->scheduled($this->now->modify('+6 hours'));

        $this->assertSame(SendOutcome::Opened, $this->app->make(OrganizerSurveyService::class)->send($survey->id(), $this->organizer->id));
        $this->travelTo($this->now->modify('+7 hours'));
        $this->artisanCommand('surveys:send-scheduled')->assertSuccessful();

        $opened = $this->surveys->findOrFail($survey->id());
        $this->assertEquals($this->now, $opened->opensAt());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
    }

    public function test_a_survey_already_sent_is_not_sent_by_the_schedule(): void
    {
        $survey = $this->scheduled($this->now->modify('-1 minute'));

        $this->app->make(SurveyLifecycleService::class)->open($survey->id(), $this->now);

        $this->assertNull($this->app->make(OrganizerSurveyService::class)->sendScheduled($survey->id(), $this->now));
    }

    public function test_runs_every_five_minutes(): void
    {
        $events = array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'surveys:send-scheduled'),
        );

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', array_first($events)->expression);
    }

    private function scheduled(DateTimeImmutable $at): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        $survey = $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: null);
        $survey->scheduleSending($at);
        $this->surveys->save($survey);

        return $survey;
    }

    private function moderator(): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        foreach (['admin.access' => ['admin', 'access', null], 'surveys:surveys.moderate' => ['surveys', 'moderate', 'surveys']] as $key => [$resource, $action, $module]) {
            $permission = PermissionModel::query()->firstOrCreate(['key' => $key], ['label' => $key, 'resource' => $resource, 'action' => $action, 'module' => $module]);
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
