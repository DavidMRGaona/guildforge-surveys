<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Application;

use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\DTOs\OrganizerSurveyInput;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Whether a survey mails a summary when it closes starts from the setting and may change until
 * it closes.
 */
final class ClosingSummaryChoiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

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
        ExampleThingSource::$audience = new SurveyAudienceDTO([]);
        $this->travelTo(new DateTimeImmutable('2030-01-04T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_a_subject_survey_from_a_template_takes_the_setting(): void
    {
        $lifecycle = $this->app->make(SurveyLifecycleService::class);

        $on = $lifecycle->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $this->template(), false, 3, null);

        config()->set('modules.settings.surveys.closing_summary_by_default', false);
        $off = $lifecycle->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $this->template(), false, 3, null);

        $this->assertTrue($this->stored($on)->sendsClosingSummary());
        $this->assertFalse($this->stored($off)->sendsClosingSummary());
    }

    public function test_a_subject_survey_created_directly_takes_the_setting(): void
    {
        config()->set('modules.settings.surveys.closing_summary_by_default', false);

        $id = $this->app->make(SurveyLifecycleService::class)->createForSubject(
            ExampleThingSource::TYPE,
            'thing-1',
            'Encuesta de ejemplo',
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            true,
            null,
        );

        $this->assertFalse($this->app->make(SurveyRepositoryInterface::class)->findOrFail($id)->sendsClosingSummary());
    }

    public function test_an_organizer_survey_takes_the_setting_unless_the_editor_says_otherwise(): void
    {
        $service = $this->app->make(OrganizerSurveyService::class);

        $default = $service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Form, $this->input());
        $chosen = $service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Form, $this->input(closingSummary: false));

        $this->assertTrue($this->stored($default)->sendsClosingSummary());
        $this->assertFalse($this->stored($chosen)->sendsClosingSummary());
        $this->assertTrue($service->defaultsFor(ExampleThingSource::TYPE, SurveyKind::Form)['closingSummary']);
    }

    public function test_the_editor_changes_it_and_leaves_it_alone_when_it_sends_nothing(): void
    {
        $service = $this->app->make(OrganizerSurveyService::class);
        $survey = $service->create(ExampleThingSource::TYPE, 'thing-1', $this->organizer->id, SurveyKind::Form, $this->input());

        $service->update($survey->id(), $this->organizer->id, $this->input(closingSummary: false));
        $this->assertFalse($this->stored($survey)->sendsClosingSummary());

        $service->update($survey->id(), $this->organizer->id, $this->input());
        $this->assertFalse($this->stored($survey)->sendsClosingSummary());
    }

    private function stored(Survey $survey): Survey
    {
        return $this->app->make(SurveyRepositoryInterface::class)->findOrFail($survey->id());
    }

    private function input(?bool $closingSummary = null): OrganizerSurveyInput
    {
        return new OrganizerSurveyInput(
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(['sections' => [[
                'id' => 'section-1',
                'title' => null,
                'description' => null,
                'questions' => [[
                    'id' => 'question-1',
                    'key' => null,
                    'type' => 'short_text',
                    'title' => '¿Qué te gustaría jugar?',
                    'help' => null,
                    'required' => true,
                ]],
            ]]]),
            anonymous: false,
            durationDays: 3,
            closesAt: null,
            reminderHoursBefore: null,
            liveResults: false,
            visibleToRespondents: true,
            closingSummary: $closingSummary,
        );
    }

    private function template(): SurveyTemplate
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Plantilla de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $template;
    }
}
