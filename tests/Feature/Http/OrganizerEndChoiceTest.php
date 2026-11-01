<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Http;

use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use DateTimeImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SubjectEndChoiceModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Organizers without the panel choose their subject's end survey on its surveys page, with the
 * same options as the subject's form in the panel.
 */
final class OrganizerEndChoiceTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private UserModel $organizer;

    private SurveyTemplate $otherTemplate;

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

        $typeTemplate = $this->template('Valoración de ejemplo');
        $this->otherTemplate = $this->template('Torneo de ejemplo');
        config()->set('modules.settings.surveys.subject_types', [
            ExampleThingSource::TYPE => ['template_id' => $typeTemplate->id()->value, 'auto_send' => true, 'send_delay_hours' => 12],
        ]);

        $this->travelTo(new DateTimeImmutable('2030-01-04T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_subject_page_offers_the_end_survey_choice(): void
    {
        $this->actingAs($this->organizer)->get(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('endChoice.selected', 'type-default')
                ->where('endChoice.ownValue', 'own')
                ->where('endChoice.locked', false)
                ->where('endChoice.url', route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-1']))
                ->where('endChoice.options.0', ['value' => 'type-default', 'label' => 'Plantilla del tipo: «Valoración de ejemplo»'])
                ->has('endChoice.options', 4)
                ->has('endChoice.baseTemplates', 2));
    }

    public function test_choosing_none_saves_it(): void
    {
        $this->actingAs($this->organizer)
            ->from(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->post(route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-1']), ['choice' => 'none'])
            ->assertRedirect(route('surveys.subject', [ExampleThingSource::TYPE, 'thing-1']))
            ->assertSessionHas('success', __('surveys::messages.manage.end_choice_saved'));

        $this->assertSame(SubjectEndChoiceModel::MODE_NONE, SubjectEndChoiceModel::query()->value('mode'));
    }

    public function test_an_own_survey_opens_in_the_editor(): void
    {
        $response = $this->actingAs($this->organizer)
            ->post(route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-1']), [
                'choice' => 'own',
                'base' => 'template:'.$this->otherTemplate->id()->value,
            ]);

        $survey = $this->app->make(SurveyRepositoryInterface::class)->findBySubjectEnd(ExampleThingSource::TYPE.':thing-1');
        $this->assertInstanceOf(Survey::class, $survey);
        $this->assertSame('Torneo de ejemplo', $survey->title());
        $response->assertRedirect(route('surveys.manage.edit', $survey->id()->value));
    }

    public function test_a_value_that_is_not_offered_is_refused(): void
    {
        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-1']), ['choice' => 'template:'.SurveyTemplateId::generate()->value])
            ->assertSessionHasErrors('choice');

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-1']), ['choice' => 'own', 'base' => 'template:nope'])
            ->assertSessionHasErrors('base');

        $this->assertSame(0, SubjectEndChoiceModel::query()->count());
    }

    public function test_a_locked_choice_is_not_saved(): void
    {
        ExampleThingSource::$status = SurveySubjectStatus::Ended;

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-1']), ['choice' => 'none'])
            ->assertSessionHas('error', __('surveys::messages.end_choice.locked.ended'));

        $this->assertSame(0, SubjectEndChoiceModel::query()->count());
    }

    public function test_strangers_and_unknown_subjects_are_refused(): void
    {
        $this->actingAs(UserModel::factory()->create())
            ->post(route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-1']), ['choice' => 'none'])
            ->assertForbidden();

        $this->actingAs($this->organizer)
            ->post(route('surveys.manage.end-choice', [ExampleThingSource::TYPE, 'thing-404']), ['choice' => 'none'])
            ->assertNotFound();
    }

    private function template(string $name): SurveyTemplate
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            $name,
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $template;
    }
}
