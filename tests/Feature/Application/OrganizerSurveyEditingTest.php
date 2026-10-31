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
use Modules\Surveys\Application\DTOs\OrganizerSurveyInput;
use Modules\Surveys\Application\Enums\UpdateOutcome;
use Modules\Surveys\Application\Exceptions\OrganizerChangeRejectedException;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyDefinitionException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\DefinitionViolation;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Notifications\SurveySubmittedForReview;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * What an organizer may change on the public site, and what sends it to review.
 */
final class OrganizerSurveyEditingTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private UserModel $player;

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
        $this->player = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO([$this->player->id, UserModel::factory()->create()->id]);

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

    public function test_saving_a_changed_draft_marks_it_without_sending_it(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->offered();

        $outcome = $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, title: 'Otro título', durationDays: 3));

        $this->assertSame(UpdateOutcome::Saved, $outcome);
        $saved = $this->surveys->findOrFail($survey->id());
        $this->assertSame('Otro título', $saved->title());
        $this->assertSame(3, $saved->durationDays());
        $this->assertSame(SurveyStatus::Draft, $saved->status());
        $this->assertTrue($saved->hasUnreviewedChanges());
        $this->assertSame($this->organizer->id, $saved->lastChangedBy());
        Notification::assertNothingSent();
    }

    public function test_an_open_survey_changed_with_review_on_goes_to_review(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $moderator = $this->moderator();
        $survey = $this->opened();

        $outcome = $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, description: 'Nueva descripción'));

        $this->assertSame(UpdateOutcome::SentForReview, $outcome);
        $pending = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::PendingReview, $pending->status());
        $this->assertFalse($pending->isAcceptingResponses($this->now));
        Notification::assertSentTo($moderator, SurveySubmittedForReview::class);
    }

    public function test_an_open_survey_changed_with_review_off_stays_open(): void
    {
        $survey = $this->opened();

        $this->assertSame(UpdateOutcome::Saved, $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, title: 'Otro título')));
        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_dates_anonymity_and_results_are_not_content(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->opened(anonymous: false);
        $closesAt = $this->now->modify('+10 days');

        $outcome = $this->service->update($survey->id(), $this->organizer->id, $this->input(
            $survey,
            anonymous: true,
            closesAt: $closesAt,
            reminderHoursBefore: 24,
            liveResults: true,
            visibleToRespondents: true,
        ));

        $this->assertSame(UpdateOutcome::Saved, $outcome);
        $saved = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Open, $saved->status());
        $this->assertFalse($saved->hasUnreviewedChanges());
        $this->assertTrue($saved->isAnonymous());
        $this->assertEquals($closesAt, $saved->closesAt());
        $this->assertSame(24, $saved->reminderHoursBefore());
        $this->assertTrue($saved->results()->liveResults);
        $this->assertTrue($saved->results()->visibleToRespondents);
    }

    public function test_with_a_response_the_content_is_locked(): void
    {
        $survey = $this->opened();
        SurveyResponseModel::query()->create(['survey_id' => $survey->id()->value, 'submitted_on' => '2030-01-05']);

        try {
            $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, definition: $this->retitledQuestion($survey)));
            $this->fail('The change went through');
        } catch (InvalidSurveyDefinitionException $exception) {
            $this->assertContainsEquals(new DefinitionViolation('sections', 'locked_by_responses'), $exception->violations);
        }

        $this->assertEquals($survey->definition(), $this->surveys->findOrFail($survey->id())->definition());
    }

    public function test_with_a_response_the_title_is_locked_too(): void
    {
        $survey = $this->opened();
        SurveyResponseModel::query()->create(['survey_id' => $survey->id()->value, 'submitted_on' => '2030-01-05']);

        try {
            $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, title: 'Otro título'));
            $this->fail('The change went through');
        } catch (OrganizerChangeRejectedException $exception) {
            $this->assertSame('title', $exception->field);
            $this->assertSame('locked_by_responses', $exception->messageKey);
        }
    }

    public function test_an_organizer_never_adds_a_key(): void
    {
        $survey = $this->offered();
        $data = $survey->definition()->toArray();
        $data['sections'][0]['questions'][2]['key'] = 'mood';

        $this->expectException(InvalidSurveyDefinitionException::class);

        $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, definition: SurveyDefinition::fromArray($data)));
    }

    public function test_content_waiting_for_review_does_not_change_under_the_moderator(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->opened();
        $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, title: 'Enviada a revisión'));
        $pending = $this->surveys->findOrFail($survey->id());

        try {
            $this->service->update($survey->id(), $this->organizer->id, $this->input($pending, title: 'Cambiada después'));
            $this->fail('The change went through');
        } catch (OrganizerChangeRejectedException $exception) {
            $this->assertSame('pending_review', $exception->messageKey);
        }

        $this->assertSame('Enviada a revisión', $this->surveys->findOrFail($survey->id())->title());
    }

    public function test_dates_still_change_while_waiting_for_review(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->opened();
        $this->service->update($survey->id(), $this->organizer->id, $this->input($survey, title: 'Enviada a revisión'));
        $pending = $this->surveys->findOrFail($survey->id());

        $this->assertSame(UpdateOutcome::Saved, $this->service->update($survey->id(), $this->organizer->id, $this->input($pending, reminderHoursBefore: 12)));
        $this->assertSame(12, $this->surveys->findOrFail($survey->id())->reminderHoursBefore());
    }

    public function test_a_closed_survey_is_not_edited(): void
    {
        $survey = $this->opened();
        $this->app->make(SurveyLifecycleService::class)->close($survey->id(), $this->now);

        $this->expectException(OrganizerChangeRejectedException::class);

        $this->service->update($survey->id(), $this->organizer->id, $this->input($survey));
    }

    public function test_strangers_cannot_edit(): void
    {
        $survey = $this->offered();

        $this->expectException(AuthorizationException::class);

        $this->service->update($survey->id(), UserModel::factory()->create()->id, $this->input($survey, title: 'Ajeno'));
    }

    private function input(
        Survey $survey,
        ?string $title = null,
        ?string $description = null,
        ?SurveyDefinition $definition = null,
        ?bool $anonymous = null,
        ?int $durationDays = null,
        ?DateTimeImmutable $closesAt = null,
        ?int $reminderHoursBefore = null,
        bool $liveResults = false,
        bool $visibleToRespondents = false,
    ): OrganizerSurveyInput {
        return new OrganizerSurveyInput(
            title: $title ?? $survey->title(),
            description: $description ?? $survey->description(),
            definition: $definition ?? $survey->definition(),
            anonymous: $anonymous ?? $survey->isAnonymous(),
            durationDays: $durationDays ?? $survey->durationDays(),
            closesAt: $closesAt ?? $survey->closesAt(),
            reminderHoursBefore: $reminderHoursBefore ?? $survey->reminderHoursBefore(),
            liveResults: $liveResults,
            visibleToRespondents: $visibleToRespondents,
        );
    }

    private function retitledQuestion(Survey $survey): SurveyDefinition
    {
        $data = $survey->definition()->toArray();
        $data['sections'][0]['questions'][0]['title'] = 'Otra pregunta';

        return SurveyDefinition::fromArray($data);
    }

    private function offered(bool $anonymous = true): Survey
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
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: $anonymous, durationDays: 7, subjectEndKey: null);
    }

    private function opened(bool $anonymous = true): Survey
    {
        $survey = $this->offered($anonymous);
        $this->app->make(SurveyLifecycleService::class)->open($survey->id(), $this->now);

        return $this->surveys->findOrFail($survey->id());
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
