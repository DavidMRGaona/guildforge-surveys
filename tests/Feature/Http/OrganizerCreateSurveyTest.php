<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The pages where whoever manages a subject writes a survey or a poll of their own, and closes
 * an open one by hand.
 */
final class OrganizerCreateSurveyTest extends ModuleTestCase
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
        ExampleThingSource::$audience = new SurveyAudienceDTO([UserModel::factory()->create()->id, UserModel::factory()->create()->id]);
        config()->set('modules.settings.surveys.subject_types', [ExampleThingSource::TYPE => ['close_days' => 3, 'anonymous' => true]]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-05T20:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_create_page_opens_the_editor_with_a_blank_poll(): void
    {
        $this->actingAs($this->organizer)->get(route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Edit', false)
                ->where('survey.id', null)
                ->where('survey.kind', 'poll')
                ->where('survey.title', '')
                ->where('survey.anonymous', true)
                ->where('survey.durationDays', 3)
                ->where('survey.liveResults', true)
                ->where('survey.visibleToRespondents', true)
                ->where('survey.closingSummary', true)
                ->where('survey.opened', false)
                ->where('survey.sendable', true)
                ->where('definition.sections.0.questions.0.type', 'single_choice')
                ->has('definition.sections.0.questions.0.options', 2)
                ->where('subject.title', 'Partida de ejemplo')
                ->where('locked', false)
                ->where('endSending', null)
                ->where('submitUrl', route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']))
                ->where('submitMethod', 'post')
                ->where('backUrl', route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1'])));
    }

    public function test_the_create_page_of_a_form_starts_with_a_short_answer(): void
    {
        $this->actingAs($this->organizer)->get(route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'encuesta']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('survey.kind', 'form')
                ->where('survey.liveResults', false)
                ->where('definition.sections.0.questions.0.type', 'short_text'));
    }

    public function test_strangers_and_unknown_subjects_or_kinds_are_turned_away(): void
    {
        $stranger = UserModel::factory()->create();

        $this->actingAs($stranger)->get(route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']))->assertForbidden();
        $this->actingAs($stranger)->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']), $this->pollPayload())->assertForbidden();
        $this->actingAs($this->organizer)->get(route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-404', 'sondeo']))->assertNotFound();
        $this->actingAs($this->organizer)->get('/encuestas/sujeto/'.ExampleThingSource::TYPE.'/thing-1/nueva/quiz')->assertNotFound();
        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_saving_creates_a_draft_and_opens_its_editor(): void
    {
        $response = $this->actingAs($this->organizer)
            ->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']), $this->pollPayload());

        $model = SurveyModel::query()->sole();
        $response->assertRedirect(route('surveys.manage.edit', $model->id))
            ->assertSessionHas('success', __('surveys::messages.manage.created'));
        $survey = $this->surveys->findOrFail(SurveyId::fromString((string) $model->id));
        $this->assertSame(SurveyKind::Poll, $survey->kind());
        $this->assertSame(SurveyStatus::Draft, $survey->status());
        $this->assertSame('¿Qué día jugamos?', $survey->title());
        $this->assertSame($this->organizer->id, $survey->createdBy());
    }

    public function test_saving_and_sending_opens_it_and_goes_back_to_the_subject(): void
    {
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']).'?send=1', $this->pollPayload())
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertSessionHas('success', __('surveys::messages.manage.sent'));

        $this->assertSame(SurveyStatus::Open->value, SurveyModel::query()->sole()->status);
    }

    public function test_saving_and_sending_with_review_on_waits_for_a_moderator(): void
    {
        config()->set('modules.settings.surveys.review_required', true);

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']).'?send=1', $this->pollPayload())
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertSessionHas('success', __('surveys::messages.manage.sent_for_review'));

        $this->assertSame(SurveyStatus::PendingReview->value, SurveyModel::query()->sole()->status);
    }

    public function test_a_forged_poll_comes_back_with_errors_on_its_fields(): void
    {
        $payload = $this->pollPayload();
        $payload['definition']['sections'][0]['questions'][0]['type'] = 'short_text';

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']), $payload)
            ->assertSessionHasErrors(['definition.sections.0.questions.0.type' => __('surveys::messages.definition_errors.poll_type')]);

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_a_cancelled_subject_takes_no_new_surveys(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        $this->actingAs($this->organizer)->get(route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'encuesta']))
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']));
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']), $this->pollPayload())
            ->assertSessionHas('error', __('surveys::messages.manage.create_unavailable'));

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_the_subject_page_offers_to_create_until_the_subject_is_cancelled(): void
    {
        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('createUrls.form', route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'encuesta']))
                ->where('createUrls.poll', route('surveys.manage.create', [ExampleThingSource::TYPE, 'thing-1', 'sondeo'])));

        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertInertia(fn (Assert $page) => $page->where('createUrls', null));
    }

    public function test_an_organizer_closes_an_open_survey(): void
    {
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.store', [ExampleThingSource::TYPE, 'thing-1', 'sondeo']).'?send=1', $this->pollPayload());
        $id = (string) SurveyModel::query()->sole()->id;

        $this->actingAs(UserModel::factory()->create())->post(route('surveys.manage.close', $id))->assertForbidden();
        $this->actingAs($this->organizer)->post(route('surveys.manage.close', $id))
            ->assertSessionHas('success', __('surveys::messages.manage.closed'));
        $this->actingAs($this->organizer)->post(route('surveys.manage.close', $id))
            ->assertSessionHas('error', __('surveys::messages.manage.not_closable'));

        $this->assertSame(SurveyStatus::Closed->value, SurveyModel::query()->sole()->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function pollPayload(): array
    {
        return [
            'title' => '¿Qué día jugamos?',
            'description' => null,
            'definition' => ['sections' => [[
                'id' => 'section-1',
                'title' => null,
                'description' => null,
                'questions' => [[
                    'id' => 'question-1',
                    'key' => null,
                    'type' => 'single_choice',
                    'title' => '¿Qué día jugamos?',
                    'help' => null,
                    'required' => true,
                    'options' => [['id' => 'option-1', 'label' => 'Viernes'], ['id' => 'option-2', 'label' => 'Sábado']],
                    'allow_other' => false,
                    'scale' => null,
                    'stars' => null,
                    'grid' => null,
                ]],
            ]]],
            'anonymous' => true,
            'duration_days' => 3,
            'closes_at' => null,
            'reminder_hours_before' => null,
            'live_results' => true,
            'visible_to_respondents' => true,
        ];
    }
}
