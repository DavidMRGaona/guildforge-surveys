<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Notifications\SurveySubmittedForReview;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class OrganizerSurveyServiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private OrganizerSurveyService $service;

    private SurveyRepositoryInterface $surveys;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);

        $this->organizer = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO(UserModel::factory()->count(2)->create()->pluck('id')->all());

        $this->service = $this->app->make(OrganizerSurveyService::class);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->now = new DateTimeImmutable('2030-01-05T20:00:00+00:00');
        $this->travelTo($this->now);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_sending_an_untouched_template_opens_it_even_with_review_on(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->offered();

        $outcome = $this->service->send($survey->id(), $this->organizer->id);

        $this->assertSame(SendOutcome::Opened, $outcome);
        $opened = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Open, $opened->status());
        $this->assertEquals($this->now->modify('+7 days'), $opened->closesAt());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
    }

    public function test_sending_with_unreviewed_changes_waits_for_review(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $moderator = $this->userWith(['admin.access', 'surveys:surveys.moderate']);
        $moderatorOutsideThePanel = $this->userWith(['surveys:surveys.moderate']);
        $survey = $this->offered();
        $survey->markUnreviewedChanges($this->organizer->id);
        $this->surveys->save($survey);

        $outcome = $this->service->send($survey->id(), $this->organizer->id);

        $this->assertSame(SendOutcome::SentForReview, $outcome);
        $pending = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::PendingReview, $pending->status());
        $this->assertSame($this->organizer->id, $pending->submittedBy());
        $this->assertSame(0, SurveyRecipientModel::query()->count());
        Notification::assertNotSentTo(UserModel::query()->whereKeyNot([$moderator->id, $moderatorOutsideThePanel->id])->get(), SurveyInvitation::class);
        Notification::assertSentTo($moderator, SurveySubmittedForReview::class);
        Notification::assertNotSentTo($moderatorOutsideThePanel, SurveySubmittedForReview::class);
    }

    public function test_with_review_off_changes_open_at_once(): void
    {
        $survey = $this->offered();
        $survey->markUnreviewedChanges($this->organizer->id);
        $this->surveys->save($survey);

        $this->assertSame(SendOutcome::Opened, $this->service->send($survey->id(), $this->organizer->id));
        $this->assertFalse($this->surveys->findOrFail($survey->id())->hasUnreviewedChanges());
    }

    public function test_strangers_cannot_send(): void
    {
        $survey = $this->offered();

        $this->expectException(AuthorizationException::class);

        $this->service->send($survey->id(), UserModel::factory()->create()->id);
    }

    public function test_strangers_cannot_dismiss(): void
    {
        $survey = $this->offered();

        $this->expectException(AuthorizationException::class);

        $this->service->dismiss($survey->id(), UserModel::factory()->create()->id);
    }

    public function test_a_disabled_source_closes_the_door(): void
    {
        $survey = $this->offered();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->clear();

        $this->expectException(AuthorizationException::class);

        $this->service->send($survey->id(), $this->organizer->id);
    }

    public function test_dismissing_keeps_the_survey_unsent(): void
    {
        $survey = $this->offered();
        $survey->scheduleSending($this->now->modify('+12 hours'));
        $this->surveys->save($survey);

        $this->service->dismiss($survey->id(), $this->organizer->id);

        $dismissed = $this->surveys->findOrFail($survey->id());
        $this->assertEquals($this->now, $dismissed->dismissedAt());
        $this->assertNull($dismissed->scheduledSendAt());
        $this->assertSame(SurveyStatus::Draft, $dismissed->status());
    }

    public function test_resuming_a_rejected_open_survey_does_not_mail_again(): void
    {
        $survey = $this->offered();
        $this->service->send($survey->id(), $this->organizer->id);
        $opened = $this->surveys->findOrFail($survey->id());
        $opened->submitForReview($this->organizer->id, $this->now);
        $opened->reject('moderator', 'Motivo de ejemplo', $this->now);
        $this->surveys->save($opened);

        $this->assertSame(SendOutcome::Opened, $this->service->send($survey->id(), $this->organizer->id));

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
        $this->assertSame(2, SurveyRecipientModel::query()->count());
        Notification::assertSentTimes(SurveyInvitation::class, 2);
    }

    private function offered(): Survey
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: null);
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                $key === 'admin.access'
                    ? ['label' => $key, 'resource' => 'admin', 'action' => 'access']
                    : ['label' => $key, 'resource' => 'surveys', 'action' => 'moderate', 'module' => 'surveys'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
