<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use DateTimeImmutable;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\AudienceType;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\EditSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyLifecycleActionsTest extends ModuleTestCase
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

    public function test_opens_a_draft_and_closes_it(): void
    {
        $survey = $this->survey(closesAt: now()->addDays(3)->toDateTimeImmutable());

        Livewire::test(ListSurveys::class)
            ->callAction(TestAction::make('open')->table($this->model($survey)))
            ->assertHasNoActionErrors();
        $this->assertSame(SurveyStatus::Open, $this->surveys->findOrFail($survey->id())->status());

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->callAction('close');
        $this->assertSame(SurveyStatus::Closed, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_a_draft_whose_closing_date_has_passed_does_not_open(): void
    {
        $survey = $this->survey(closesAt: now()->subHour()->toDateTimeImmutable());

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->callAction('open')
            ->assertNotified(__('surveys::filament.actions.open_failed'));

        $this->assertSame(SurveyStatus::Draft, $this->surveys->findOrFail($survey->id())->status());
    }

    public function test_deleting_needs_the_second_confirmation(): void
    {
        $survey = $this->survey();
        SurveyResponseModel::query()->create(['survey_id' => $survey->id()->value, 'submitted_on' => '2030-01-02']);

        Livewire::test(ListSurveys::class)
            ->callAction(TestAction::make('delete')->table($this->model($survey)), data: ['confirmation' => false])
            ->assertHasActionErrors(['confirmation']);
        $this->assertNotNull($this->surveys->find($survey->id()));

        Livewire::test(ListSurveys::class)
            ->callAction(TestAction::make('delete')->table($this->model($survey)), data: ['confirmation' => true])
            ->assertHasNoActionErrors();
        $this->assertNull($this->surveys->find($survey->id()));
        $this->assertSame(0, SurveyResponseModel::query()->count());
    }

    private function survey(?DateTimeImmutable $closesAt = null): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Encuesta de ejemplo',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: true,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: AudienceType::AllUsers,
            closesAt: $closesAt,
        );
        $this->surveys->save($survey);

        return $survey;
    }

    private function model(Survey $survey): SurveyModel
    {
        return SurveyModel::query()->findOrFail($survey->id()->value);
    }
}
