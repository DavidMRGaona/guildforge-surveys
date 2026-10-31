<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\EditSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurvey;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Notifications\SurveyInvitation;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Approving and rejecting in the panel, for whoever holds surveys.moderate, also without
 * surveys.manage (they review from the read-only page).
 */
final class SurveyReviewActionsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private SurveyRepositoryInterface $surveys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->registerOnAdminPanel([SurveyResource::class]);

        $this->organizer = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO([UserModel::factory()->create()->id]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-05T20:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_a_moderator_without_manage_reviews_from_the_read_only_page(): void
    {
        $survey = $this->pending();
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));

        Livewire::test(ViewSurvey::class, ['record' => $survey->id()->value])
            ->assertOk()
            ->assertActionVisible('approve')
            ->assertActionVisible('reject')
            ->callAction('approve');

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_rejecting_needs_a_reason(): void
    {
        $survey = $this->pending();
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));

        Livewire::test(ViewSurvey::class, ['record' => $survey->id()->value])
            ->callAction('reject', data: ['reason' => ''])
            ->assertHasActionErrors(['reason' => 'required']);
        $this->assertSame(SurveyStatus::PendingReview, $this->surveys->findOrFail($survey->id())->status());

        Livewire::test(ViewSurvey::class, ['record' => $survey->id()->value])
            ->callAction('reject', data: ['reason' => 'Falta una pregunta'])
            ->assertHasNoActionErrors();

        $rejected = $this->surveys->findOrFail($survey->id());
        $this->assertSame(SurveyStatus::Draft, $rejected->status());
        $this->assertSame('Falta una pregunta', $rejected->rejectionReason());
    }

    public function test_managers_without_moderate_do_not_see_the_review_actions(): void
    {
        $survey = $this->pending();
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.manage']));

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject');
    }

    public function test_the_review_actions_only_show_while_pending(): void
    {
        $survey = $this->pending();
        $this->surveys->save($this->approved($survey));
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));

        Livewire::test(ViewSurvey::class, ['record' => $survey->id()->value])
            ->assertActionHidden('approve')
            ->assertActionHidden('reject');
    }

    public function test_a_decision_on_a_survey_reviewed_meanwhile_changes_nothing(): void
    {
        $survey = $this->pending();
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));
        $page = Livewire::test(ViewSurvey::class, ['record' => $survey->id()->value]);
        $this->surveys->save($this->approved($survey));

        // Whether Livewire hides it or the service refuses it, a second decision changes nothing
        $page->callAction('approve');

        $this->assertSame(SurveyStatus::Draft, $this->surveys->findOrFail($survey->id())->status());
        Notification::assertSentTimes(SurveyInvitation::class, 0);
    }

    public function test_moderators_see_the_list_and_the_survey(): void
    {
        $survey = $this->pending();
        $moderator = $this->userWith(['admin.access', 'surveys:surveys.moderate']);
        $model = SurveyModel::query()->findOrFail($survey->id()->value);

        $this->assertTrue($moderator->can('viewAny', SurveyModel::class));
        $this->assertTrue($moderator->can('view', $model));
        $this->assertTrue($moderator->can('moderate', $model));
        $this->assertFalse($moderator->can('update', $model));
        $this->assertFalse($this->userWith(['admin.access', 'surveys:surveys.manage'])->can('moderate', $model));
    }

    private function approved(Survey $survey): Survey
    {
        $fresh = $this->surveys->findOrFail($survey->id());
        $fresh->approve('00000000-0000-4000-8000-0000000000aa', new DateTimeImmutable);

        return $fresh;
    }

    private function pending(): Survey
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
        $survey->markUnreviewedChanges($this->organizer->id);
        $survey->submitForReview($this->organizer->id, new DateTimeImmutable);
        $this->surveys->save($survey);

        return $survey;
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        foreach ($permissionKeys as $key) {
            [$resource, $action, $module] = $key === 'admin.access' ? ['admin', 'access', null] : ['surveys', substr($key, strrpos($key, '.') + 1), 'surveys'];
            $permission = PermissionModel::query()->firstOrCreate(['key' => $key], ['label' => $key, 'resource' => $resource, 'action' => $action, 'module' => $module]);
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
