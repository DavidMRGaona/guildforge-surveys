<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Infrastructure\Knowledge;

use App\Application\Knowledge\Services\KnowledgeCapabilityRegistryInterface;
use App\Application\Surveys\DTOs\SurveyAudienceDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Illuminate\Support\Facades\Notification;
use Modules\Surveys\Application\Services\SurveyLifecycleService;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Tests\Support\BuildsTrendSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use stdClass;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The capabilities the module's guides use answer like the profile's surveys tab does.
 */
final class SurveyKnowledgeCapabilitiesTest extends ModuleTestCase
{
    use BuildsTrendSurveys;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private KnowledgeCapabilityRegistryInterface $capabilities;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        Notification::fake();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->capabilities = $this->app->make(KnowledgeCapabilityRegistryInterface::class);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_module_registers_its_capabilities(): void
    {
        $keys = $this->capabilities->keys();

        $this->assertContains('surveys.organizes_surveys', $keys);
        $this->assertContains('surveys.has_trends', $keys);
    }

    public function test_organizes_surveys_passes_for_whoever_manages_a_subject_with_surveys(): void
    {
        $organizer = UserModel::factory()->create();
        $player = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$organizer->id];
        ExampleThingSource::$managers = [$organizer->id];
        ExampleThingSource::$audience = new SurveyAudienceDTO([$player->id]);
        $this->offeredSurvey();

        $this->assertTrue($this->capabilities->evaluate('surveys.organizes_surveys', $organizer));
        $this->assertFalse($this->capabilities->evaluate('surveys.organizes_surveys', $player));
    }

    public function test_organizes_surveys_fails_once_the_person_no_longer_manages_the_subject(): void
    {
        $organizer = UserModel::factory()->create();
        ExampleThingSource::$organizerIds = [$organizer->id];
        ExampleThingSource::$managers = [$organizer->id];
        $this->offeredSurvey();
        ExampleThingSource::$managers = [];

        $this->assertFalse($this->capabilities->evaluate('surveys.organizes_surveys', $organizer));
    }

    public function test_organizes_surveys_fails_without_any_survey(): void
    {
        $organizer = UserModel::factory()->create();
        ExampleThingSource::$managers = [$organizer->id];

        $this->assertFalse($this->capabilities->evaluate('surveys.organizes_surveys', $organizer));
    }

    public function test_has_trends_passes_only_with_template_surveys_naming_the_person(): void
    {
        $this->template = $this->saveTemplate($this->definition());
        [$ana, $bea] = $this->people(2);
        $this->survey(['starts' => '2027-01-10', 'organizers' => [$ana]]);

        $this->assertTrue($this->capabilities->evaluate('surveys.has_trends', UserModel::query()->findOrFail($ana)));
        $this->assertFalse($this->capabilities->evaluate('surveys.has_trends', UserModel::query()->findOrFail($bea)));
    }

    public function test_capabilities_fail_closed_for_something_that_is_not_an_account(): void
    {
        $this->assertFalse($this->capabilities->evaluate('surveys.organizes_surveys', new stdClass));
        $this->assertFalse($this->capabilities->evaluate('surveys.has_trends', new stdClass));
    }

    private function offeredSurvey(): void
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Partida',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(['sections' => [[
                'id' => 's-1',
                'title' => null,
                'description' => null,
                'questions' => [[
                    'id' => 'q-short', 'key' => null, 'type' => 'short_text', 'title' => 'Pregunta', 'help' => null,
                    'required' => false, 'options' => [], 'allow_other' => false, 'scale' => null, 'stars' => null, 'grid' => null,
                ]],
            ]]]),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        $this->app->make(SurveyLifecycleService::class)
            ->createFromTemplateForSubject(ExampleThingSource::TYPE, 'thing-1', $template, anonymous: true, durationDays: 7, subjectEndKey: null);
    }
}
