<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Services\TimezoneServiceInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class OrganizerSurveyEditorTest extends ModuleTestCase
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
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->travelTo(new DateTimeImmutable('2030-01-05T20:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_editor_opens_with_the_survey_and_its_content(): void
    {
        $survey = $this->offered();

        $this->actingAs($this->organizer)->get(route('surveys.manage.edit', $survey->id()->value))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Surveys/Manage/Edit', false)
                ->where('survey.title', 'Valoración de ejemplo')
                ->where('survey.durationDays', 7)
                ->where('survey.opened', false)
                ->where('survey.sendable', true)
                ->where('locked', false)
                ->where('definition', $survey->definition()->toArray())
                ->where('submitUrl', route('surveys.manage.update', $survey->id()->value))
                ->where('submitMethod', 'put')
                ->where('backUrl', route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1'])));
    }

    public function test_strangers_cannot_open_or_save_the_editor(): void
    {
        $survey = $this->offered();
        $stranger = UserModel::factory()->create();

        $this->actingAs($stranger)->get(route('surveys.manage.edit', $survey->id()->value))->assertForbidden();
        $this->actingAs($stranger)->put(route('surveys.manage.update', $survey->id()->value), $this->payload($survey, ['title' => 'Ajeno']))->assertForbidden();
        $this->assertSame('Valoración de ejemplo', $this->surveys->findOrFail($survey->id())->title());
    }

    public function test_saving_keeps_the_organizer_on_the_editor(): void
    {
        $survey = $this->offered();

        $this->actingAs($this->organizer)->from(route('surveys.manage.edit', $survey->id()->value))
            ->put(route('surveys.manage.update', $survey->id()->value), $this->payload($survey, ['title' => 'Otro título', 'duration_days' => 3]))
            ->assertRedirect(route('surveys.manage.edit', $survey->id()->value))
            ->assertSessionHas('success', __('surveys::messages.manage.saved'));

        $saved = $this->surveys->findOrFail($survey->id());
        $this->assertSame('Otro título', $saved->title());
        $this->assertSame(3, $saved->durationDays());
    }

    public function test_saving_and_sending_goes_back_to_the_subject(): void
    {
        $survey = $this->offered();

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.update', $survey->id()->value).'?send=1', $this->payload($survey, ['title' => 'Otro título']))
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertSessionHas('success', __('surveys::messages.manage.sent'));

        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_a_forged_key_comes_back_on_its_field_and_nothing_is_saved(): void
    {
        $survey = $this->offered();
        $definition = $survey->definition()->toArray();
        $definition['sections'][0]['questions'][1]['key'] = 'mood';

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.update', $survey->id()->value), $this->payload($survey, ['title' => 'Otro título', 'definition' => $definition]))
            ->assertSessionHasErrors(['definition.sections.0.questions.1.key' => __('surveys::messages.definition_errors.key_not_allowed')]);

        $this->assertSame('Valoración de ejemplo', $this->surveys->findOrFail($survey->id())->title());
    }

    public function test_a_broken_definition_comes_back_on_its_field(): void
    {
        $survey = $this->offered();
        $definition = $survey->definition()->toArray();
        $definition['sections'][0]['questions'][0]['title'] = '';

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.update', $survey->id()->value), $this->payload($survey, ['definition' => $definition]))
            ->assertSessionHasErrors(['definition.sections.0.questions.0.title']);
    }

    public function test_with_a_response_the_content_comes_back_locked(): void
    {
        $survey = $this->offered();
        $this->app->make(SurveyLifecycleService::class)->open($survey->id());
        SurveyResponseModel::query()->create(['survey_id' => $survey->id()->value, 'submitted_on' => '2030-01-05']);
        $definition = $survey->definition()->toArray();
        $definition['sections'][0]['questions'][0]['title'] = 'Otra pregunta';

        $this->actingAs($this->organizer)->get(route('surveys.manage.edit', $survey->id()->value))
            ->assertInertia(fn (Assert $page) => $page->where('locked', true));

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.update', $survey->id()->value), $this->payload($this->surveys->findOrFail($survey->id()), ['definition' => $definition]))
            ->assertSessionHasErrors(['definition.sections' => __('surveys::messages.definition_errors.locked_by_responses')]);
    }

    public function test_the_closing_date_is_read_in_the_display_zone(): void
    {
        $survey = $this->offered();
        $this->app->make(SurveyLifecycleService::class)->open($survey->id());
        $opened = $this->surveys->findOrFail($survey->id());

        $this->actingAs($this->organizer)
            ->put(route('surveys.manage.update', $survey->id()->value), $this->payload($opened, ['closes_at' => '2030-01-20T18:30']))
            ->assertSessionHasNoErrors();

        $expected = app(TimezoneServiceInterface::class)->fromDisplayWallTime('2030-01-20T18:30');
        $this->assertEquals($expected, $this->surveys->findOrFail($survey->id())->closesAt());
    }

    public function test_a_closed_survey_sends_the_organizer_back(): void
    {
        $survey = $this->offered();
        $lifecycle = $this->app->make(SurveyLifecycleService::class);
        $lifecycle->open($survey->id());
        $lifecycle->close($survey->id());

        $this->actingAs($this->organizer)->get(route('surveys.manage.edit', $survey->id()->value))
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertSessionHas('error', __('surveys::messages.manage.not_editable'));
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
}
