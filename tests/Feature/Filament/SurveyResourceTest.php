<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use DateTimeImmutable;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\PublicLinkMode;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\CreateSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\EditSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyResourceTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyRepositoryInterface $surveys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->actingAsPanelAdmin();
    }

    public function test_creates_a_form_survey_for_some_roles(): void
    {
        Livewire::test(CreateSurvey::class)
            ->fillForm([
                'title' => 'Encuesta de la junta',
                'sections' => DefinitionFormState::fromDefinition($this->definition()),
                'anonymous' => true,
                'audience_type' => 'roles',
                'audience_roles' => ['member'],
                'closes_at' => '2030-01-10 20:00:00',
                'live_results' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $survey = $this->stored();
        $this->assertSame(SurveyKind::Form, $survey->kind());
        $this->assertSame(AudienceType::Roles, $survey->audienceType());
        $this->assertSame(['member'], $survey->audienceRoles());
        $this->assertTrue($survey->isAnonymous());
        $this->assertTrue($survey->results()->liveResults);
        $this->assertCount(10, $survey->definition()->questions());
    }

    public function test_the_public_link_asks_for_an_email_unless_it_is_made_open(): void
    {
        $form = [
            'title' => 'Encuesta abierta',
            'sections' => DefinitionFormState::fromDefinition($this->definition()),
            'anonymous' => true,
            'audience_type' => 'all_users',
            'closes_at' => '2030-01-10 20:00:00',
            'public_link_enabled' => true,
        ];

        Livewire::test(CreateSurvey::class)
            ->fillForm($form)
            ->assertFormFieldIsVisible('public_link_mode')
            ->assertSchemaStateSet(['public_link_mode' => PublicLinkMode::VerifiedEmail->value])
            ->fillForm(['public_link_mode' => PublicLinkMode::Open->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(PublicLinkMode::Open, $this->stored()->publicLinkMode());
        $this->assertTrue($this->stored()->isPublicLinkEnabled());
    }

    public function test_the_public_link_mode_is_fixed_once_the_survey_opens(): void
    {
        $survey = $this->survey();

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->fillForm(['public_link_enabled' => true])
            ->assertFormFieldIsDisabled('public_link_mode');
    }

    public function test_starting_from_a_template_copies_its_questions_with_their_keys(): void
    {
        $template = new SurveyTemplate(SurveyTemplateId::generate(), 'Tras la partida', null, SurveyKind::Form, $this->definition());
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        Livewire::test(CreateSurvey::class)
            ->fillForm(['title' => 'Desde plantilla', 'audience_type' => 'all_users'])
            ->set('data.template_id', $template->id()->value)
            ->call('create')
            ->assertHasNoFormErrors();

        $survey = $this->stored();
        $this->assertTrue($survey->templateId()?->equals($template->id()));
        $keys = array_map(static fn ($question): ?string => $question->key, $survey->definition()->questions());
        $this->assertContains('fun', $keys);
        $this->assertNull($survey->definition()->question('q-scale'), 'The copy gets new ids.');
    }

    public function test_general_templates_are_offered_first_to_start_from(): void
    {
        $repository = $this->app->make(SurveyTemplateRepositoryInterface::class);
        $templates = [];
        foreach (['De mesas' => ['game-table'], 'Junta' => [TemplateUses::GENERAL], 'Sin indicar' => []] as $name => $uses) {
            $templates[$name] = new SurveyTemplate(SurveyTemplateId::generate(), $name, null, SurveyKind::Form, $this->definition(), uses: new TemplateUses($uses));
            $repository->save($templates[$name]);
        }

        Livewire::test(CreateSurvey::class)
            ->assertFormFieldExists('template_id', static fn (Select $field): bool => $field->getOptions() === [
                'Para encuestas generales' => [$templates['Junta']->id()->value => 'Junta'],
                'Otras' => [$templates['De mesas']->id()->value => 'De mesas', $templates['Sin indicar']->id()->value => 'Sin indicar'],
            ]);
    }

    public function test_creates_a_quick_poll(): void
    {
        Livewire::test(ListSurveys::class)
            ->callAction('quick_poll', data: $this->quickPollData())
            ->assertHasNoActionErrors();

        $poll = $this->stored();
        $this->assertFalse($poll->results()->liveResults);
        $this->assertSame(SurveyKind::Poll, $poll->kind());
        $this->assertFalse($poll->isAnonymous());
        $this->assertTrue($poll->results()->visibleToRespondents);
        $question = $poll->definition()->questions()[0];
        $this->assertSame(QuestionType::MultipleChoice, $question->type);
        $this->assertSame(['Viernes', 'Sábado', 'Domingo'], array_map(static fn ($option): string => $option->label, $question->options));
    }

    public function test_creates_a_quick_poll_after_another_action_was_mounted(): void
    {
        // Under Xdebug's develop mode, an action mounted earlier in the process made callAction skip this one
        Livewire::test(ListSurveys::class)->mountAction('quick_poll');

        Livewire::test(ListSurveys::class)
            ->callAction('quick_poll', data: $this->quickPollData())
            ->assertHasNoActionErrors();

        $this->assertSame(SurveyKind::Poll, $this->stored()->kind());
    }

    public function test_with_responses_only_help_texts_change(): void
    {
        $survey = $this->survey();
        SurveyResponseModel::query()->create(['survey_id' => $survey->id()->value, 'submitted_on' => '2030-01-02']);

        $page = Livewire::test(EditSurvey::class, ['record' => $survey->id()->value]);
        /** @var array<string, array<string, mixed>> $sections */
        $sections = $page->get('data.sections');
        $sectionKey = array_keys($sections)[0];
        $questionKey = array_keys($sections[$sectionKey]['questions'])[0];

        $withHelp = $sections;
        $withHelp[$sectionKey]['questions'][$questionKey]['data']['help'] = 'Como te llamen en la mesa';
        $page->fillForm(['sections' => $withHelp])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Como te llamen en la mesa', $this->surveys->findOrFail($survey->id())->definition()->question('q-short')?->help);

        $page->assertFormFieldIsDisabled("sections.{$sectionKey}.next");

        $retitled = $withHelp;
        $retitled[$sectionKey]['questions'][$questionKey]['data']['title'] = 'Otra pregunta';
        $page->fillForm(['sections' => $retitled])->call('save')->assertHasFormErrors(['sections']);
        $this->assertSame('Pregunta q-short', $this->surveys->findOrFail($survey->id())->definition()->question('q-short')->title);
    }

    public function test_an_anonymous_survey_never_becomes_named(): void
    {
        $survey = $this->survey();

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->assertFormFieldIsDisabled('anonymous')
            ->set('data.anonymous', false)
            ->call('save');

        $this->assertTrue($this->surveys->findOrFail($survey->id())->isAnonymous());
    }

    public function test_the_announcement_box_starts_from_the_setting(): void
    {
        Livewire::test(CreateSurvey::class)->assertSchemaStateSet(['announce_in_channels' => false]);

        config()->set('modules.settings.surveys.announce_in_channels_by_default', true);

        Livewire::test(CreateSurvey::class)->assertSchemaStateSet(['announce_in_channels' => true]);
    }

    public function test_creating_a_survey_keeps_whether_it_is_announced(): void
    {
        Livewire::test(CreateSurvey::class)
            ->fillForm([
                'title' => 'Horario del local',
                'sections' => DefinitionFormState::fromDefinition($this->definition()),
                'anonymous' => true,
                'audience_type' => 'all_users',
                'closes_at' => '2030-01-10 20:00:00',
                'announce_in_channels' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->stored()->announceInChannels());
    }

    public function test_a_draft_can_change_whether_it_is_announced(): void
    {
        $survey = $this->survey(open: false);

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->assertFormFieldIsEnabled('announce_in_channels')
            ->fillForm(['announce_in_channels' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->stored()->announceInChannels());
    }

    public function test_an_opened_survey_keeps_whether_it_was_announced(): void
    {
        $survey = $this->survey();

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->assertFormFieldIsDisabled('announce_in_channels');
    }

    public function test_a_subject_survey_has_no_announcement_box(): void
    {
        $survey = $this->survey(open: false);
        SurveyModel::query()->whereKey($survey->id()->value)->update(['subject_type' => 'example-thing', 'subject_id' => 'thing-1']);

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->assertFormFieldIsHidden('announce_in_channels');
    }

    public function test_a_quick_poll_can_be_announced(): void
    {
        Livewire::test(ListSurveys::class)
            ->callAction('quick_poll', data: [...$this->quickPollData(), 'announce_in_channels' => true])
            ->assertHasNoActionErrors();

        $this->assertTrue($this->stored()->announceInChannels());
    }

    private function survey(bool $open = true): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: $this->definition(),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
            closesAt: new DateTimeImmutable('2030-01-10T20:00:00+00:00'),
        );
        if ($open) {
            $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        }
        $this->surveys->save($survey);

        return $survey;
    }

    /**
     * @return array<string, mixed>
     */
    private function quickPollData(): array
    {
        return [
            'question' => '¿Qué día hacemos la asamblea?',
            'options' => [['label' => 'Viernes'], ['label' => 'Sábado'], ['label' => 'Domingo']],
            'multiple' => true,
            'anonymous' => false,
            'live_results' => false,
            'closes_at' => '2030-01-10 20:00:00',
        ];
    }

    private function stored(): Survey
    {
        return $this->surveys->findOrFail(SurveyId::fromString(SurveyModel::query()->sole()->id));
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }
}
