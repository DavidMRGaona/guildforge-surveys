<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use DateTimeImmutable;
use Filament\Forms\Components\Builder;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\Slot;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\EditSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurveyResults;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Unit\Domain\Services\AvailabilityDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The board proposes dates from the panel: a quick action like the quick poll, and the editor
 * with the slots typed in the site's zone.
 */
final class AvailabilitySurveyPanelTest extends ModuleTestCase
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

    public function test_creates_a_named_availability_survey_from_the_list(): void
    {
        Livewire::test(ListSurveys::class)
            ->callAction('quick_availability', data: [
                'question' => '¿Qué día hacemos la asamblea?',
                'slots' => [
                    ['day' => '2030-01-12', 'start' => null, 'end' => null],
                    ['day' => '2030-01-17', 'start' => '18:00', 'end' => '21:00'],
                ],
                'closes_at' => '2030-01-10 20:00:00',
            ])
            ->assertHasNoActionErrors();

        $survey = $this->surveys->findOrFail(SurveyId::fromString(SurveyModel::query()->sole()->id));
        $this->assertSame(SurveyKind::Availability, $survey->kind());
        $this->assertSame('¿Qué día hacemos la asamblea?', $survey->title());
        $this->assertFalse($survey->isAnonymous());
        $this->assertTrue($survey->results()->liveResults);
        $this->assertTrue($survey->results()->visibleToRespondents);
        $question = $survey->definition()->questions()[0];
        $this->assertSame(QuestionType::Availability, $question->type);
        $this->assertEquals('2030-01-12', $question->slots[0]->date);
        $this->assertEquals(['2030-01-17T17:00:00+00:00', '2030-01-17T20:00:00+00:00'], [$question->slots[1]->startsAt, $question->slots[1]->endsAt]);
    }

    public function test_the_quick_action_needs_two_dates(): void
    {
        Livewire::test(ListSurveys::class)
            ->callAction('quick_availability', data: [
                'question' => '¿Qué día?',
                'slots' => [['day' => '2030-01-12', 'start' => null, 'end' => null]],
                'closes_at' => '2030-01-10 20:00:00',
            ])
            ->assertHasActionErrors(['slots']);

        $this->assertSame(0, SurveyModel::query()->count());
    }

    public function test_the_editor_shows_the_slots_in_the_site_zone_and_saves_them_back(): void
    {
        $survey = $this->survey();

        $page = Livewire::test(EditSurvey::class, ['record' => $survey->id()->value]);
        /** @var array<string, array<string, mixed>> $sections */
        $sections = $page->get('data.sections');
        $sectionKey = array_keys($sections)[0];
        /** @var array<string, array{type: string, data: array<string, mixed>}> $questions */
        $questions = $sections[$sectionKey]['questions'];
        $questionKey = array_keys($questions)[0];
        /** @var array<string, array<string, mixed>> $slots */
        $slots = $questions[$questionKey]['data']['slots'];
        $this->assertSame('availability', $questions[$questionKey]['type']);
        // The date picker holds the day with a time of its own
        $this->assertSame(
            [['d-1', '2026-12-05', null, null], ['d-2', '2026-12-11', '17:00', '21:00']],
            array_map(static fn (array $slot): array => [$slot['id'], substr((string) $slot['day'], 0, 10), $slot['start'], $slot['end']], array_values($slots)),
        );

        $slotKey = array_keys($slots)[1];
        $page->set("data.sections.{$sectionKey}.questions.{$questionKey}.data.slots.{$slotKey}.end", '22:30')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEquals(new Slot('d-2', startsAt: '2026-12-11T16:00:00+00:00', endsAt: '2026-12-11T21:30:00+00:00'), $this->surveys->findOrFail($survey->id())->definition()->questions()[0]->slots[1]);
    }

    public function test_a_slot_error_lands_on_its_field(): void
    {
        $sections = DefinitionFormState::fromDefinition(SurveyDefinition::fromArray(AvailabilityDefinitionTest::data()));
        $sectionKey = array_keys($sections)[0];

        $this->assertMatchesRegularExpression(
            "/^sections\\.{$sectionKey}\\.questions\\.[^.]+\\.data\\.slots\\.[^.]+\\.start$/",
            DefinitionFormState::formPath($sections, 'sections.0.questions.0.slots.1.starts_at'),
        );
        $this->assertMatchesRegularExpression('/\.slots\.[^.]+\.day$/', DefinitionFormState::formPath($sections, 'sections.0.questions.0.slots.0.date'));
        $this->assertMatchesRegularExpression('/\.slots\.[^.]+\.end$/', DefinitionFormState::formPath($sections, 'sections.0.questions.0.slots.1.ends_at'));
        $this->assertMatchesRegularExpression('/\.slots\.[^.]+\.day$/', DefinitionFormState::formPath($sections, 'sections.0.questions.0.slots.1'));
    }

    public function test_an_availability_survey_offers_only_its_own_block_and_a_form_never_offers_it(): void
    {
        $availability = Livewire::test(EditSurvey::class, ['record' => $this->survey()->id()->value]);
        $this->assertSame(['availability'], $this->blockNames($availability));

        $form = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta',
            description: null,
            definition: SurveyDefinition::fromArray(['sections' => [['id' => 's-1', 'questions' => [['id' => 'q-1', 'type' => 'short_text', 'title' => '¿Algo?', 'required' => false]]]]]),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
        );
        $this->surveys->save($form);
        $this->assertNotContains('availability', $this->blockNames(Livewire::test(EditSurvey::class, ['record' => $form->id()->value])));
    }

    public function test_it_never_offers_to_become_a_template(): void
    {
        Livewire::test(EditSurvey::class, ['record' => $this->survey()->id()->value])
            ->assertActionHidden('save_as_template');
    }

    public function test_the_results_page_shows_each_slot_and_fixes_the_date_once_confirmed(): void
    {
        Notification::fake();
        $survey = $this->survey();
        $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        $survey->close(new DateTimeImmutable('2030-01-02T10:00:00+00:00'));
        $this->surveys->save($survey);

        Livewire::test(ViewSurveyResults::class, ['record' => $survey->id()->value])
            ->assertSee('Sábado, 5 de diciembre de 2026')
            ->assertSee('Si hace falta')
            ->assertActionVisible('choose_date')
            ->callAction('choose_date', data: ['slot_id' => 'd-2', 'confirmed' => false])
            ->assertHasActionErrors(['confirmed']);
        $before = $this->surveys->findOrFail($survey->id());
        $this->assertNull($before->chosenSlot());

        Livewire::test(ViewSurveyResults::class, ['record' => $survey->id()->value])
            ->callAction('choose_date', data: ['slot_id' => 'd-2', 'confirmed' => true])
            ->assertHasNoActionErrors();

        $this->assertSame('d-2', $this->surveys->findOrFail($survey->id())->chosenSlot()?->id);
        Livewire::test(ViewSurveyResults::class, ['record' => $survey->id()->value])
            ->assertSee('Fecha elegida')
            ->assertActionHidden('choose_date');
    }

    public function test_an_open_survey_has_no_date_to_fix_yet(): void
    {
        $survey = $this->survey();
        $survey->open(new DateTimeImmutable('2030-01-01T10:00:00+00:00'));
        $this->surveys->save($survey);

        Livewire::test(ViewSurveyResults::class, ['record' => $survey->id()->value])
            ->assertActionHidden('choose_date');
    }

    /**
     * @return list<string>
     */
    private function blockNames(mixed $page): array
    {
        /** @var EditSurvey $instance */
        $instance = $page->instance();
        $builder = $instance->form->getComponent(static fn ($component): bool => $component instanceof Builder);
        $this->assertInstanceOf(Builder::class, $builder);

        return array_values(array_map(static fn ($block): string => $block->getName(), $builder->getBlocks()));
    }

    private function survey(): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Availability,
            title: '¿Qué día os viene bien para la asamblea?',
            description: null,
            definition: SurveyDefinition::fromArray(AvailabilityDefinitionTest::data()),
            anonymous: false,
            results: ResultsSettings::defaultsFor(SurveyKind::Availability),
            audienceType: AudienceType::AllUsers,
            closesAt: new DateTimeImmutable('2030-01-10T20:00:00+00:00'),
        );
        $this->surveys->save($survey);

        return $survey;
    }
}
