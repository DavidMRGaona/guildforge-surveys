<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use DateTimeImmutable;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
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

    private function survey(): Survey
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
        $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
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
