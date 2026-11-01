<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Authorization\Services\AuthorizationServiceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Whoever manages a subject proposes dates on the public site: the editor shows and takes each
 * slot as typed in the site's zone, and the response page shows them the same way.
 */
final class OrganizerAvailabilitySurveyTest extends ModuleTestCase
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
        ExampleThingSource::$audience = new SurveyAudienceDTO([$this->organizer->id, UserModel::factory()->create()->id]);
        config()->set('modules.settings.surveys.subject_types', [ExampleThingSource::TYPE => ['close_days' => 3, 'anonymous' => true]]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-05T20:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_subject_page_offers_an_availability_survey(): void
    {
        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('createUrls.availability', route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad'])));
    }

    public function test_the_create_page_starts_a_named_survey_with_two_days_to_fill_in(): void
    {
        $this->actingAs($this->organizer)->get(route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Edit', false)
                ->where('survey.kind', 'availability')
                // Named even where the kind of subject asks anonymous: who can each day is the point
                ->where('survey.anonymous', false)
                ->where('survey.liveResults', true)
                ->where('survey.visibleToRespondents', true)
                ->where('definition.sections.0.questions.0.type', 'availability')
                ->has('definition.sections.0.questions.0.slots', 2)
                ->where('definition.sections.0.questions.0.slots.0.day', '')
                ->where('definition.sections.0.questions.0.slots.0.start', null)
                ->where('submitUrl', route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad'])));
    }

    public function test_saving_stores_the_slots_typed_in_the_site_zone_as_utc(): void
    {
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad']), $this->payload())
            ->assertSessionHasNoErrors();

        $survey = $this->surveys->findOrFail(SurveyId::fromString((string) SurveyModel::query()->sole()->id));
        $this->assertSame(SurveyKind::Availability, $survey->kind());
        $this->assertFalse($survey->isAnonymous());
        $this->assertEquals([
            new Slot('slot-1', date: '2030-01-12'),
            new Slot('slot-2', startsAt: '2030-01-17T17:00:00+00:00', endsAt: '2030-01-17T20:30:00+00:00'),
        ], $survey->definition()->questions()[0]->slots);
    }

    public function test_the_editor_shows_the_slots_as_typed_and_reports_errors_on_their_fields(): void
    {
        $this->actingAs($this->organizer)->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad']), $this->payload());
        $id = (string) SurveyModel::query()->sole()->id;

        $this->actingAs($this->organizer)->get(route('surveys.manage.edit', $id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('definition.sections.0.questions.0.slots.1.day', '2030-01-17')
                ->where('definition.sections.0.questions.0.slots.1.start', '18:00')
                ->where('definition.sections.0.questions.0.slots.1.end', '21:30')
                ->where('definition.sections.0.questions.0.slots.1.label', 'jueves, 17 de enero de 2030, de 18:00 a 21:30'));

        $payload = $this->payload();
        $payload['definition']['sections'][0]['questions'][0]['slots'][1]['start'] = '7 de la tarde';
        $this->actingAs($this->organizer)->put(route('surveys.manage.update', $id), $payload)
            ->assertSessionHasErrors(['definition.sections.0.questions.0.slots.1.starts_at' => __('surveys::messages.definition_errors.invalid_slot_start')]);
    }

    public function test_the_response_page_shows_each_slot_in_the_site_zone(): void
    {
        $this->actingAs($this->organizer)->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad']).'?send=1', $this->payload());
        $id = (string) SurveyModel::query()->sole()->id;

        $this->actingAs($this->organizer)->get(route('surveys.respond', $id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('survey.kind', 'availability')
                ->where('definition.sections.0.questions.0.slots.0.label', 'sábado, 12 de enero de 2030')
                ->where('definition.sections.0.questions.0.slots.1.start', '18:00'));

        $this->app->make(SubmissionService::class)->submit(SurveyId::fromString($id), Respondent::account($this->organizer->id), ['question-1' => ['slot-1' => 'yes']]);
        $this->assertSame(2, SurveyModel::query()->sole()->responses()->first()?->answers()->count());
    }

    public function test_the_organizer_sees_each_slot_and_fixes_the_date_of_the_closed_survey(): void
    {
        $this->actingAs($this->organizer)->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad']).'?send=1', $this->payload());
        $id = (string) SurveyModel::query()->sole()->id;
        $this->app->make(SubmissionService::class)->submit(SurveyId::fromString($id), Respondent::account($this->organizer->id), ['question-1' => ['slot-2' => 'yes']]);

        $this->actingAs($this->organizer)->get(route('surveys.manage.results', $id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.questions.0.availability.slots.1.yes', 1)
                ->where('results.questions.0.availability.slots.1.best', true)
                ->where('results.questions.0.availability.slots.1.yesNames', [$this->organizer->name])
                ->where('results.questions.0.availability.chosenSlotId', null)
                // Not closed yet: there is no date to fix
                ->where('chooseDateUrl', null));

        $this->app->make(SurveyLifecycleService::class)->close(SurveyId::fromString($id));
        $this->actingAs($this->organizer)->get(route('surveys.manage.results', $id))
            ->assertInertia(fn (Assert $page) => $page->where('chooseDateUrl', route('surveys.manage.choose-date', $id)));

        $this->actingAs($this->organizer)->post(route('surveys.manage.choose-date', $id), ['slot_id' => 'slot-2'])
            ->assertSessionHasErrors('confirmed');
        $this->actingAs($this->organizer)->post(route('surveys.manage.choose-date', $id), ['slot_id' => 'slot-2', 'confirmed' => true])
            ->assertSessionHas('success', __('surveys::messages.manage.date_chosen'));

        $this->actingAs($this->organizer)->get(route('surveys.manage.results', $id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('results.questions.0.availability.chosenSlotId', 'slot-2')
                ->where('chooseDateUrl', null));
        $this->actingAs($this->organizer)->post(route('surveys.manage.choose-date', $id), ['slot_id' => 'slot-1', 'confirmed' => true])
            ->assertSessionHas('error', __('surveys::messages.manage.date_not_chosen'));
        $this->actingAs(UserModel::factory()->create())->post(route('surveys.manage.choose-date', $id), ['slot_id' => 'slot-1', 'confirmed' => true])
            ->assertForbidden();
    }

    public function test_it_is_never_offered_or_saved_as_a_template(): void
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        $permission = PermissionModel::query()->firstOrCreate(['key' => 'surveys:surveys.manage'], ['label' => 'surveys', 'resource' => 'surveys', 'action' => 'manage', 'module' => 'surveys']);
        $role->permissions()->attach($permission->id);
        $this->organizer->roles()->attach($role->id);
        $this->app->make(AuthorizationServiceInterface::class)->invalidateCacheFor($this->organizer);
        $this->actingAs($this->organizer)->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'disponibilidad']), $this->payload());
        $id = (string) SurveyModel::query()->sole()->id;

        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertInertia(fn (Assert $page) => $page->where('surveys.0.saveTemplateUrl', null));
        $this->actingAs($this->organizer)->post(route('surveys.manage.save-template', $id))->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'title' => '¿Qué día seguimos la campaña?',
            'description' => null,
            'definition' => ['sections' => [[
                'id' => 'section-1',
                'title' => null,
                'description' => null,
                'questions' => [[
                    'id' => 'question-1',
                    'key' => null,
                    'type' => 'availability',
                    'title' => '¿Qué día seguimos la campaña?',
                    'help' => null,
                    'required' => true,
                    'options' => [],
                    'allow_other' => false,
                    'scale' => null,
                    'stars' => null,
                    'grid' => null,
                    'slots' => [
                        ['id' => 'slot-1', 'day' => '2030-01-12', 'start' => null, 'end' => null],
                        ['id' => 'slot-2', 'day' => '2030-01-17', 'start' => '18:00', 'end' => '21:30'],
                    ],
                ]],
            ]]],
            'anonymous' => false,
            'duration_days' => 3,
            'closes_at' => null,
            'reminder_hours_before' => null,
            'live_results' => true,
            'visible_to_respondents' => true,
        ];
    }
}
