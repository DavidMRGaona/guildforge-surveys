<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyListTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyResource::class]);
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->actingAsPanelAdmin();
    }

    public function test_each_status_has_a_tab_with_its_count(): void
    {
        $open = [$this->survey(SurveyStatus::Open), $this->survey(SurveyStatus::Open)];
        $pending = $this->survey(SurveyStatus::PendingReview);
        $draft = $this->survey(SurveyStatus::Draft);
        $closed = $this->survey(SurveyStatus::Closed);

        $page = Livewire::test(ListSurveys::class);
        $list = $page->instance();
        $this->assertInstanceOf(ListSurveys::class, $list);
        $tabs = $list->getTabs();

        $this->assertSame(['open', 'pending_review', 'draft', 'closed', 'all'], array_keys($tabs));
        $this->assertSame(
            ['Abiertas', 'Pendientes de revisión', 'Borradores', 'Cerradas', 'Todas'],
            array_values(array_map(static fn (Tab $tab): ?string => is_string($tab->getLabel()) ? $tab->getLabel() : null, $tabs)),
        );
        $this->assertSame(
            ['2', '1', '1', '1', '5'],
            array_values(array_map(static fn (Tab $tab): ?string => $tab->getBadge(), $tabs)),
        );

        $page->set('activeTab', 'open')
            ->assertCanSeeTableRecords($this->models(...$open))
            ->assertCanNotSeeTableRecords($this->models($pending, $draft, $closed))
            ->set('activeTab', 'pending_review')
            ->assertCanSeeTableRecords($this->models($pending))
            ->assertCanNotSeeTableRecords($this->models($draft, $closed, ...$open));
    }

    public function test_it_starts_on_every_survey(): void
    {
        $open = $this->survey(SurveyStatus::Open);
        $draft = $this->survey(SurveyStatus::Draft);

        Livewire::test(ListSurveys::class)
            ->assertSet('activeTab', 'all')
            ->assertCanSeeTableRecords($this->models($open, $draft));
    }

    public function test_it_shows_what_each_survey_is_about(): void
    {
        $general = $this->survey(SurveyStatus::Open);
        $aboutThing = $this->survey(SurveyStatus::Open, $this->snapshot(ExampleThingSource::TYPE, 'Partida de Dune'));

        Livewire::test(ListSurveys::class)
            ->assertTableColumnStateSet('subject', 'General', $this->models($general)->sole())
            ->assertTableColumnStateSet('subject', 'Partida de Dune', $this->models($aboutThing)->sole())
            ->assertTableColumnHasDescription('subject', 'Cosa de ejemplo', $this->models($aboutThing)->sole());
    }

    public function test_it_finds_surveys_by_what_they_are_about(): void
    {
        $dune = $this->survey(SurveyStatus::Open, $this->snapshot(ExampleThingSource::TYPE, 'Partida de Dune'));
        $catan = $this->survey(SurveyStatus::Open, $this->snapshot(ExampleThingSource::TYPE, 'Torneo de Catan'));

        Livewire::test(ListSurveys::class)
            ->searchTable('dune')
            ->assertCanSeeTableRecords($this->models($dune))
            ->assertCanNotSeeTableRecords($this->models($catan));
    }

    public function test_it_filters_by_kind_of_subject(): void
    {
        $general = $this->survey(SurveyStatus::Open);
        $aboutThing = $this->survey(SurveyStatus::Open, $this->snapshot(ExampleThingSource::TYPE, 'Partida de Dune'));
        $retired = $this->survey(SurveyStatus::Closed, $this->snapshot('retired-thing', 'Algo de un módulo retirado'));

        Livewire::test(ListSurveys::class)
            ->assertTableFilterExists('subject_type', static fn ($filter): bool => $filter->getOptions() === [
                'general' => 'General',
                ExampleThingSource::TYPE => 'Cosa de ejemplo',
                'retired-thing' => 'retired-thing (sin módulo)',
            ])
            ->filterTable('subject_type', 'general')
            ->assertCanSeeTableRecords($this->models($general))
            ->assertCanNotSeeTableRecords($this->models($aboutThing, $retired))
            ->filterTable('subject_type', ExampleThingSource::TYPE)
            ->assertCanSeeTableRecords($this->models($aboutThing))
            ->assertCanNotSeeTableRecords($this->models($general, $retired))
            ->filterTable('subject_type', 'retired-thing')
            ->assertCanSeeTableRecords($this->models($retired))
            ->assertCanNotSeeTableRecords($this->models($general, $aboutThing));
    }

    private function survey(SurveyStatus $status, ?SubjectSnapshot $subject = null): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: $subject instanceof SubjectSnapshot ? AudienceType::Subject : AudienceType::AllUsers,
            subject: $subject,
            status: $status,
            closesAt: new DateTimeImmutable('2030-01-10T20:00:00+00:00'),
        );
        $this->app->make(SurveyRepositoryInterface::class)->save($survey);

        return $survey;
    }

    private function snapshot(string $type, string $title): SubjectSnapshot
    {
        return new SubjectSnapshot($type, (string) random_int(1, PHP_INT_MAX), $title, null, null, [], [], null);
    }

    /**
     * @return Collection<int, SurveyModel>
     */
    private function models(Survey ...$surveys): Collection
    {
        return SurveyModel::query()->whereKey(array_map(static fn (Survey $survey): string => $survey->id()->value, $surveys))->get();
    }
}
