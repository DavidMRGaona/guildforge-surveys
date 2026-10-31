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
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Preparing a subject's end survey from its page, before the subject ends, and choosing in the
 * editor how it goes out on the end.
 */
final class OrganizerPrepareTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private SurveyTemplate $template;

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
        ExampleThingSource::$audience = new SurveyAudienceDTO([UserModel::factory()->create()->id]);

        $this->template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Valoración de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($this->template);
        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => ['template_id' => $this->template->id()->value, 'auto_send' => true, 'send_delay_hours' => 12],
        ]);

        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-04T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_subject_page_offers_to_prepare_its_survey_before_the_end(): void
    {
        $this->actingAs($this->organizer)->get($this->subjectUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('subject.status', 'active')
                ->where('prepareUrl', route('surveys.manage.prepare', [ExampleThingSource::TYPE, 'thing-1']))
                ->has('surveys', 0));
    }

    public function test_no_offer_without_a_template_for_the_type(): void
    {
        config()->set('modules.settings.surveys.subject_types', []);

        $this->actingAs($this->organizer)->get($this->subjectUrl())
            ->assertInertia(fn (Assert $page) => $page->where('prepareUrl', null));
    }

    public function test_no_offer_for_a_cancelled_subject(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        $this->actingAs($this->organizer)->get($this->subjectUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('subject.status', 'cancelled')
                ->where('prepareUrl', null));
    }

    public function test_no_offer_once_the_end_survey_exists(): void
    {
        $this->prepared();

        $this->actingAs($this->organizer)->get($this->subjectUrl())
            ->assertInertia(fn (Assert $page) => $page->where('prepareUrl', null));
    }

    public function test_preparing_takes_the_organizer_to_the_editor(): void
    {
        $response = $this->actingAs($this->organizer)->post(route('surveys.manage.prepare', [ExampleThingSource::TYPE, 'thing-1']));

        $id = (string) SurveyModel::query()->value('id');
        $response->assertRedirect(route('surveys.manage.edit', $id))
            ->assertSessionHas('success', __('surveys::messages.manage.prepared'));
    }

    public function test_strangers_cannot_prepare(): void
    {
        $this->actingAs(UserModel::factory()->create())
            ->post(route('surveys.manage.prepare', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertForbidden();

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_an_unknown_subject_cannot_be_prepared(): void
    {
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.prepare', [ExampleThingSource::TYPE, 'missing']))
            ->assertNotFound();
    }

    public function test_an_unavailable_preparation_says_why(): void
    {
        config()->set('modules.settings.surveys.subject_types', []);

        $this->actingAs($this->organizer)->from($this->subjectUrl())
            ->post(route('surveys.manage.prepare', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertRedirect($this->subjectUrl())
            ->assertSessionHas('error', __('surveys::messages.manage.prepare_unavailable.no_template'));
    }

    public function test_the_list_shows_a_prepared_survey_without_sending_it(): void
    {
        $survey = $this->prepared();

        $this->actingAs($this->organizer)->get($this->subjectUrl())
            ->assertInertia(fn (Assert $page) => $page
                ->where('surveys.0.status', 'prepared')
                ->where('surveys.0.sendOnEnd', true)
                ->where('surveys.0.sendDelayHours', 12)
                ->where('surveys.0.sendUrl', null)
                ->where('surveys.0.dismissUrl', route('surveys.manage.dismiss', $survey->id()->value))
                ->where('surveys.0.editUrl', route('surveys.manage.edit', $survey->id()->value)));
    }

    public function test_the_editor_offers_the_end_sending_and_no_sending_now(): void
    {
        $survey = $this->prepared();

        $this->actingAs($this->organizer)->get(route('surveys.manage.edit', $survey->id()->value))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('survey.sendable', false)
                ->where('endSending.sendOnEnd', true)
                ->where('endSending.sendDelayHours', 12));
    }

    public function test_the_editor_saves_how_it_goes_out_on_the_end_without_review(): void
    {
        config()->set('modules.settings.surveys.review_required', true);
        $survey = $this->prepared();

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.update', $survey->id()->value), $this->payload($survey, ['send_on_end' => false, 'send_delay_hours' => 0]))
            ->assertSessionHasNoErrors();

        $saved = $this->surveys->findOrFail($survey->id());
        $this->assertFalse($saved->sendsOnEnd());
        $this->assertSame(0, $saved->sendDelayHours());
        $this->assertFalse($saved->hasUnreviewedChanges());
    }

    public function test_the_delay_cannot_be_negative(): void
    {
        $survey = $this->prepared();

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.update', $survey->id()->value), $this->payload($survey, ['send_on_end' => true, 'send_delay_hours' => -1]))
            ->assertSessionHasErrors('send_delay_hours');
    }

    public function test_saving_and_sending_a_prepared_survey_is_refused(): void
    {
        $survey = $this->prepared();

        $this->actingAs($this->organizer)->from(route('surveys.manage.edit', $survey->id()->value))
            ->put(route('surveys.manage.update', $survey->id()->value).'?send=1', $this->payload($survey))
            ->assertSessionHas('error', __('surveys::messages.manage.not_sendable'));

        $this->assertTrue($this->surveys->findOrFail($survey->id())->isAwaitingSubjectEnd());
    }

    private function subjectUrl(): string
    {
        return route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']);
    }

    private function prepared(): Survey
    {
        $survey = $this->app->make(OrganizerSurveyService::class)->prepare(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id);

        return $this->surveys->findOrFail(SurveyId::fromString($survey->id()->value));
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(Survey $survey, array $changes = []): array
    {
        return array_merge([
            'title' => $survey->title(),
            'description' => $survey->description(),
            'definition' => $survey->definition()->toArray(),
            'anonymous' => $survey->isAnonymous(),
            'duration_days' => $survey->durationDays(),
            'closes_at' => null,
            'reminder_hours_before' => $survey->reminderHoursBefore(),
            'live_results' => $survey->results()->liveResults,
            'visible_to_respondents' => $survey->results()->visibleToRespondents,
        ], $changes);
    }
}
