<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
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
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\EditSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurvey;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * «Duplicar» and «Guardar como plantilla» in the panel: from the list, the edit page and the
 * read-only page, for whoever may make surveys and templates.
 */
final class SurveyDuplicateActionsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    private SurveyRepositoryInterface $surveys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        ExampleThingSource::reset();
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->registerOnAdminPanel([SurveyResource::class, SurveyTemplateResource::class]);
        $this->surveys = $this->app->make(SurveyRepositoryInterface::class);
        $this->actingAsPanelAdmin();
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_duplicating_from_the_edit_page_opens_the_copy(): void
    {
        $survey = $this->survey();

        $page = Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->callAction('duplicate')
            ->assertNotified(__('surveys::filament.actions.duplicate_done'));

        $copy = $this->copyOf($survey);
        $page->assertRedirect(SurveyResource::getUrl('edit', ['record' => $copy->id()->value]));
        $this->assertSame('Copia de Satisfacción del trimestre', $copy->title());
        $this->assertSame(SurveyStatus::Draft, $copy->status());
    }

    public function test_duplicating_from_the_list(): void
    {
        $survey = $this->survey();

        Livewire::test(ListSurveys::class)
            ->callAction(TestAction::make('duplicate')->table(SurveyModel::query()->findOrFail($survey->id()->value)))
            ->assertHasNoActionErrors();

        $this->assertSame('Copia de Satisfacción del trimestre', $this->copyOf($survey)->title());
    }

    public function test_a_survey_of_a_cancelled_subject_is_not_duplicated(): void
    {
        $survey = $this->survey(subject: true);
        ExampleThingSource::$status = SurveySubjectStatus::Cancelled;

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->callAction('duplicate')
            ->assertNotified(__('surveys::filament.actions.duplicate_failed'));

        $this->assertSame(1, SurveyModel::query()->count());
    }

    public function test_saving_as_a_template_asks_its_name(): void
    {
        $survey = $this->survey(subject: true);

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->mountAction('save_as_template')
            ->assertActionDataSet(['name' => 'Satisfacción del trimestre'])
            ->setActionData(['name' => 'Valoración de partidas', 'description' => 'Para las mesas'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified(__('surveys::filament.actions.save_as_template_done', ['name' => 'Valoración de partidas']));

        $template = SurveyTemplateModel::query()->sole();
        $this->assertSame('Valoración de partidas', $template->name);
        $this->assertSame('Para las mesas', $template->description);
        $this->assertSame([ExampleThingSource::TYPE], $template->uses);
    }

    public function test_a_template_needs_a_name(): void
    {
        $survey = $this->survey();

        Livewire::test(EditSurvey::class, ['record' => $survey->id()->value])
            ->callAction('save_as_template', data: ['name' => ''])
            ->assertHasActionErrors(['name' => 'required']);

        $this->assertSame(0, SurveyTemplateModel::query()->count());
    }

    public function test_a_moderator_reading_a_survey_neither_duplicates_nor_keeps_it(): void
    {
        $survey = $this->survey();
        $this->actingAs($this->userWith(['admin.access', 'surveys:surveys.moderate']));

        Livewire::test(ViewSurvey::class, ['record' => $survey->id()->value])
            ->assertActionHidden('duplicate')
            ->assertActionHidden('save_as_template');
    }

    public function test_the_read_only_page_offers_both(): void
    {
        $survey = $this->survey();

        Livewire::test(ViewSurvey::class, ['record' => $survey->id()->value])
            ->assertActionVisible('duplicate')
            ->assertActionVisible('save_as_template');
    }

    private function copyOf(Survey $survey): Survey
    {
        $id = SurveyModel::query()->whereKeyNot($survey->id()->value)->sole()->id;

        return $this->surveys->findOrFail(SurveyId::fromString($id));
    }

    private function survey(bool $subject = false): Survey
    {
        $survey = new Survey(
            id: SurveyId::generate(),
            kind: SurveyKind::Form,
            title: 'Satisfacción del trimestre',
            description: null,
            definition: SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            anonymous: false,
            results: ResultsSettings::defaultsFor(SurveyKind::Form),
            audienceType: $subject ? AudienceType::Subject : AudienceType::AllUsers,
            subject: $subject ? new SubjectSnapshot(
                sourceType: ExampleThingSource::TYPE,
                subjectId: 'thing-1',
                title: 'Partida de ejemplo',
                startsAt: new DateTimeImmutable('2030-01-05T16:00:00+00:00'),
                endsAt: new DateTimeImmutable('2030-01-05T20:00:00+00:00'),
                organizerIds: [],
                attributes: [],
                url: null,
            ) : null,
            closesAt: now()->addDays(3)->toDateTimeImmutable(),
        );
        $this->surveys->save($survey);

        return $survey;
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        foreach ($permissionKeys as $key) {
            [$resource, $action, $module] = $key === 'admin.access' ? ['admin', 'access', null] : ['surveys', substr($key, strrpos($key, '.') + 1), 'surveys'];
            $permission = PermissionModel::query()->firstOrCreate(['key' => $key], ['label' => $key, 'resource' => $resource, 'action' => $action, 'module' => $module]);
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
