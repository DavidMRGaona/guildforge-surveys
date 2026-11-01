<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Repeating a survey on the public site: «Duplicar» on its card, «Partir de una encuesta
 * anterior» on the page of the subject it goes to, and «Guardar como plantilla» for whoever
 * manages the templates.
 */
final class OrganizerDuplicateSurveyTest extends ModuleTestCase
{
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

        $this->organizer = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$this->organizer->id];
        ExampleThingSource::$managers = [$this->organizer->id];
        ExampleThingSource::$otherManagers = [$this->organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO([UserModel::factory()->create()->id]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-05T20:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_duplicating_opens_the_copy_in_the_editor(): void
    {
        $original = $this->ofSubject();

        $response = $this->actingAs($this->organizer)->post(route('surveys.manage.duplicate', $original->id()->value));

        $copy = $this->copyOf($original);
        $response->assertRedirect(route('surveys.manage.edit', $copy->id()->value))
            ->assertSessionHas('success', __('surveys::messages.manage.duplicated'));
        $this->assertSame('Copia de Valoración de ejemplo', $copy->title());
        $this->assertSame('thing-1', $copy->subject()?->subjectId);
        $this->assertTrue($copy->hasUnreviewedChanges());
    }

    public function test_strangers_and_unknown_surveys_are_turned_away(): void
    {
        $original = $this->ofSubject();
        $stranger = UserModel::factory()->create();

        $this->actingAs($stranger)->post(route('surveys.manage.duplicate', $original->id()->value))->assertForbidden();
        $this->actingAs($this->organizer)->post(route('surveys.manage.duplicate', SurveyId::generate()->value))->assertNotFound();
        $this->assertSame(1, SurveyModel::query()->count());
    }

    public function test_a_cancelled_subject_gets_no_copy(): void
    {
        $original = $this->ofSubject();
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        $this->actingAs($this->organizer)->from(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->post(route('surveys.manage.duplicate', $original->id()->value))
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertSessionHas('error', __('surveys::messages.manage.not_duplicable'));
        $this->assertSame(1, SurveyModel::query()->count());
    }

    public function test_copying_a_survey_to_another_subject_from_its_page(): void
    {
        $original = $this->ofSubject();

        $response = $this->actingAs($this->organizer)
            ->post(route('surveys.manage.copy', [ExampleThingSource::TYPE, 'thing-3']), ['survey' => $original->id()->value]);

        $copy = $this->copyOf($original);
        $response->assertRedirect(route('surveys.manage.edit', $copy->id()->value));
        $this->assertSame('thing-3', $copy->subject()?->subjectId);
        $this->assertSame('Valoración de ejemplo', $copy->title());
    }

    public function test_copying_needs_both_subjects_managed(): void
    {
        $original = $this->ofSubject();
        ExampleThingSource::$otherManagers = [];

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.copy', [ExampleThingSource::TYPE, 'thing-3']), ['survey' => $original->id()->value])
            ->assertForbidden();

        ExampleThingSource::$otherManagers = [$this->organizer->id];
        ExampleThingSource::$managers = [];
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.copy', [ExampleThingSource::TYPE, 'thing-3']), ['survey' => $original->id()->value])
            ->assertForbidden();

        $this->assertSame(1, SurveyModel::query()->count());
    }

    public function test_copying_asks_which_survey(): void
    {
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.copy', [ExampleThingSource::TYPE, 'thing-3']), ['survey' => 'not-a-survey'])
            ->assertSessionHasErrors('survey');
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.copy', [ExampleThingSource::TYPE, 'thing-404']), ['survey' => $this->ofSubject()->id()->value])
            ->assertNotFound();
    }

    public function test_the_subject_page_offers_the_surveys_to_start_from(): void
    {
        $original = $this->ofSubject();

        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-3']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Subject', false)
                ->where('copy.url', route('surveys.manage.copy', [ExampleThingSource::TYPE, 'thing-3']))
                ->where('copy.surveys.0.value', $original->id()->value)
                ->where('copy.surveys.0.label', 'Valoración de ejemplo · Partida de ejemplo'));
    }

    public function test_a_cancelled_subject_offers_nothing_to_start_from(): void
    {
        $this->ofSubject();
        ExampleThingSource::$otherStatus = SurveySubjectStatus::Cancelled;

        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-3']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('copy', null));
    }

    public function test_cards_offer_saving_as_a_template_only_to_whoever_manages_templates(): void
    {
        $original = $this->ofSubject();

        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('surveys.0.duplicateUrl', route('surveys.manage.duplicate', $original->id()->value))
                ->where('surveys.0.saveTemplateUrl', null));

        $this->grant($this->organizer, 'surveys:surveys.manage');
        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('surveys.0.saveTemplateUrl', route('surveys.manage.save-template', $original->id()->value)));
    }

    public function test_saving_as_a_template(): void
    {
        $original = $this->ofSubject();
        $this->grant($this->organizer, 'surveys:surveys.manage');

        $this->actingAs($this->organizer)->post(route('surveys.manage.save-template', $original->id()->value))
            ->assertSessionHas('success', __('surveys::messages.manage.saved_as_template', ['name' => 'Valoración de ejemplo']));

        $template = SurveyTemplateModel::query()->where('name', 'Valoración de ejemplo')->whereKeyNot($original->templateId()?->value)->sole();
        $this->assertSame([ExampleThingSource::TYPE], $template->uses);
        $this->assertSame($this->organizer->id, $template->created_by);
    }

    public function test_saving_as_a_template_needs_both_the_survey_and_the_permission(): void
    {
        $original = $this->ofSubject();

        $this->actingAs($this->organizer)->post(route('surveys.manage.save-template', $original->id()->value))->assertForbidden();

        $stranger = UserModel::factory()->create();
        $this->grant($stranger, 'surveys:surveys.manage');
        $this->actingAs($stranger)->post(route('surveys.manage.save-template', $original->id()->value))->assertForbidden();

        $this->assertSame(1, SurveyTemplateModel::query()->count());
    }

    private function copyOf(Survey $original): Survey
    {
        $id = SurveyModel::query()->whereKeyNot($original->id()->value)->sole()->id;

        return $this->surveys->findOrFail(SurveyId::fromString($id));
    }

    private function ofSubject(): Survey
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

    private function grant(UserModel $user, string $key): void
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        $permission = PermissionModel::query()->firstOrCreate(['key' => $key], ['label' => $key, 'resource' => 'surveys', 'action' => 'manage', 'module' => 'surveys']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id);
        $this->app->make(AuthorizationServiceInterface::class)->invalidateCacheFor($user);
    }
}
