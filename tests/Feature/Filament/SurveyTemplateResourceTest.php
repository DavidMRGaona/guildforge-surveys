<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\Section;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\CreateSurveyTemplate;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\EditSurveyTemplate;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\ListSurveyTemplates;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyTemplateResourceTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([SurveyTemplateResource::class], [SurveyTrendsPage::class]);
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
    }

    public function test_creates_a_template_through_the_domain(): void
    {
        $this->actingAsPanelAdmin();

        Livewire::test(CreateSurveyTemplate::class)
            ->fillForm([
                'name' => 'Tras la partida',
                'kind' => 'form',
                'sections' => DefinitionFormState::fromDefinition($this->definition()),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $model = SurveyTemplateModel::query()->sole();
        $template = $this->app->make(SurveyTemplateRepositoryInterface::class)->findOrFail(SurveyTemplateId::fromString($model->id));
        $this->assertCount(10, $template->definition()->questions());
        $this->assertSame('fun', $template->definition()->question('q-scale')?->key);
    }

    public function test_shows_domain_errors_on_their_field(): void
    {
        $this->actingAsPanelAdmin();
        $sections = DefinitionFormState::fromDefinition($this->definition());
        $sectionKey = array_keys($sections)[0];
        $questionKey = array_keys($sections[$sectionKey]['questions'])[2];
        $sections[$sectionKey]['questions'][$questionKey]['data']['options'] = [array_first($sections[$sectionKey]['questions'][$questionKey]['data']['options'])];

        Livewire::test(CreateSurveyTemplate::class)
            ->fillForm(['name' => 'Rota', 'kind' => 'form', 'sections' => $sections])
            ->call('create')
            ->assertHasFormErrors(["sections.{$sectionKey}.questions.{$questionKey}.data.options"]);

        $this->assertSame(0, SurveyTemplateModel::query()->count());
    }

    public function test_creates_a_template_with_section_jumps(): void
    {
        $this->actingAsPanelAdmin();
        $definition = SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition());
        $sections = DefinitionFormState::fromDefinition($definition);
        [$start, $campaign] = array_keys($sections);
        $question = array_key_first($sections[$start]['questions']);
        $option = "sections.{$start}.questions.{$question}.data.options.".array_key_first($sections[$start]['questions'][$question]['data']['options']).'.next';

        Livewire::test(CreateSurveyTemplate::class)
            ->fillForm(['name' => 'Campaña', 'kind' => 'form'])
            // Replaces the empty section a new form starts with
            ->set('data.sections', $sections)
            // Each section offers the later ones and the end, never itself or an earlier one
            ->assertFormFieldExists("sections.{$start}.next", checkFieldUsing: static fn (Select $field): bool => array_keys($field->getOptions()) === ['s-campaign', 's-end', Section::SUBMIT])
            ->assertFormFieldExists("sections.{$campaign}.next", checkFieldUsing: static fn (Select $field): bool => array_keys($field->getOptions()) === ['s-end', Section::SUBMIT])
            ->assertFormFieldIsVisible($option)
            ->assertFormFieldIsVisible("sections.{$start}.questions.{$question}.data.other_next")
            ->call('create')
            ->assertHasNoFormErrors();

        $model = SurveyTemplateModel::query()->sole();
        $this->assertEquals($definition, $this->app->make(SurveyTemplateRepositoryInterface::class)->findOrFail(SurveyTemplateId::fromString($model->id))->definition());
    }

    public function test_a_backward_jump_shows_on_its_field(): void
    {
        $this->actingAsPanelAdmin();
        $sections = DefinitionFormState::fromDefinition(SurveyDefinition::fromArray(SurveyDefinitionTest::branchingDefinition()));
        $last = array_keys($sections)[2];
        $sections[$last]['next'] = 's-campaign';

        Livewire::test(CreateSurveyTemplate::class)
            ->fillForm(['name' => 'Campaña', 'kind' => 'form', 'sections' => $sections])
            ->call('create')
            ->assertHasFormErrors(["sections.{$last}.next"]);

        $this->assertSame(0, SurveyTemplateModel::query()->count());
    }

    public function test_a_keyed_question_cannot_change_type(): void
    {
        $this->actingAsPanelAdmin();
        $template = new SurveyTemplate(SurveyTemplateId::generate(), 'Plantilla', null, SurveyKind::Form, $this->definition());
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        $page = Livewire::test(EditSurveyTemplate::class, ['record' => $template->id()->value]);
        /** @var array<string, array<string, mixed>> $sections */
        $sections = $page->get('data.sections');
        $sectionKey = array_keys($sections)[1];
        $questionKey = array_keys($sections[$sectionKey]['questions'])[0];
        $sections[$sectionKey]['questions'][$questionKey]['type'] = 'star_rating';
        $sections[$sectionKey]['questions'][$questionKey]['data']['stars'] = 5;

        $page->fillForm(['sections' => $sections])
            ->call('save')
            ->assertHasFormErrors(["sections.{$sectionKey}.questions.{$questionKey}.data.title"]);

        $stored = $this->app->make(SurveyTemplateRepositoryInterface::class)->findOrFail($template->id());
        $this->assertSame('linear_scale', $stored->definition()->question('q-scale')?->type->value);
    }

    public function test_renames_and_edits_a_template(): void
    {
        $this->actingAsPanelAdmin();
        $template = new SurveyTemplate(SurveyTemplateId::generate(), 'Plantilla', null, SurveyKind::Form, $this->definition());
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        Livewire::test(EditSurveyTemplate::class, ['record' => $template->id()->value])
            ->fillForm(['name' => 'Plantilla renombrada'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $this->app->make(SurveyTemplateRepositoryInterface::class)->findOrFail($template->id());
        $this->assertSame('Plantilla renombrada', $stored->name());
        $this->assertEquals($this->definition(), $stored->definition());
    }

    public function test_saves_what_a_template_is_for(): void
    {
        $this->actingAsPanelAdmin();

        Livewire::test(CreateSurveyTemplate::class)
            ->fillForm([
                'name' => 'Tras la partida',
                'kind' => 'form',
                'uses' => [ExampleThingSource::TYPE, TemplateUses::GENERAL],
                'sections' => DefinitionFormState::fromDefinition($this->definition()),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $model = SurveyTemplateModel::query()->sole();
        $this->assertSame([ExampleThingSource::TYPE, TemplateUses::GENERAL], $model->uses);
        $template = $this->app->make(SurveyTemplateRepositoryInterface::class)->findOrFail(SurveyTemplateId::fromString($model->id));
        $this->assertSame([ExampleThingSource::TYPE, TemplateUses::GENERAL], $template->uses()->values);
    }

    public function test_a_template_for_nothing_in_particular_stores_no_use(): void
    {
        $this->actingAsPanelAdmin();

        Livewire::test(CreateSurveyTemplate::class)
            ->fillForm(['name' => 'Para todo', 'kind' => 'form', 'uses' => [], 'sections' => DefinitionFormState::fromDefinition($this->definition())])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(SurveyTemplateModel::query()->sole()->uses);
    }

    public function test_offers_general_and_the_kinds_of_subject_installed(): void
    {
        $this->actingAsPanelAdmin();

        Livewire::test(CreateSurveyTemplate::class)
            ->assertFormFieldExists('uses', static fn ($field): bool => $field->getOptions() === [
                TemplateUses::GENERAL => 'General',
                ExampleThingSource::TYPE => 'Cosa de ejemplo',
            ]);
    }

    public function test_editing_keeps_a_kind_of_subject_whose_module_is_gone(): void
    {
        $this->actingAsPanelAdmin();
        $template = $this->template('Del módulo retirado', ['retired-thing']);

        Livewire::test(EditSurveyTemplate::class, ['record' => $template->id()->value])
            ->assertFormFieldExists('uses', static fn ($field): bool => ($field->getOptions()['retired-thing'] ?? null) === 'retired-thing (sin módulo)')
            ->fillForm(['name' => 'Renombrada'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['retired-thing'], SurveyTemplateModel::query()->sole()->uses);
    }

    public function test_filters_templates_by_what_they_are_for(): void
    {
        $this->actingAsPanelAdmin();
        $forThing = $this->template('De cosas', [ExampleThingSource::TYPE]);
        $general = $this->template('General', [TemplateUses::GENERAL]);
        $both = $this->template('De todo', [TemplateUses::GENERAL, ExampleThingSource::TYPE]);
        $unspecified = $this->template('Sin indicar', []);

        Livewire::test(ListSurveyTemplates::class)
            ->filterTable('uses', ExampleThingSource::TYPE)
            ->assertCanSeeTableRecords($this->models($forThing, $both))
            ->assertCanNotSeeTableRecords($this->models($general, $unspecified))
            ->filterTable('uses', TemplateUses::GENERAL)
            ->assertCanSeeTableRecords($this->models($general, $both))
            ->assertCanNotSeeTableRecords($this->models($forThing, $unspecified))
            ->filterTable('uses', SurveyTemplateResource::UNSPECIFIED_USE)
            ->assertCanSeeTableRecords($this->models($unspecified))
            ->assertCanNotSeeTableRecords($this->models($forThing, $general, $both));
    }

    public function test_shows_what_each_template_is_for(): void
    {
        $this->actingAsPanelAdmin();
        $template = $this->template('De cosas', [ExampleThingSource::TYPE, TemplateUses::GENERAL]);
        $unspecified = $this->template('Sin indicar', []);

        Livewire::test(ListSurveyTemplates::class)
            ->assertTableColumnStateSet('uses', ['Cosa de ejemplo', 'General'], $this->models($template)->sole())
            ->assertTableColumnStateSet('uses', null, $this->models($unspecified)->sole())
            ->assertSee('Sin indicar');
    }

    public function test_filters_recommended_and_own_templates(): void
    {
        $this->actingAsPanelAdmin();
        $own = $this->template('Propia', []);
        $recommended = $this->template('Recomendada', []);
        SurveyTemplateModel::query()->whereKey($recommended->id()->value)->update(['catalog_key' => 'game_table']);

        Livewire::test(ListSurveyTemplates::class)
            ->filterTable('recommended', true)
            ->assertCanSeeTableRecords($this->models($recommended))
            ->assertCanNotSeeTableRecords($this->models($own))
            ->filterTable('recommended', false)
            ->assertCanSeeTableRecords($this->models($own))
            ->assertCanNotSeeTableRecords($this->models($recommended));
    }

    public function test_only_people_who_manage_surveys_see_templates(): void
    {
        $this->actingAs($this->userWith('surveys:surveys.view_results'));
        $this->assertFalse(SurveyTemplateResource::canViewAny());

        $this->actingAs($this->userWith('surveys:surveys.manage'));
        $this->assertTrue(SurveyTemplateResource::canViewAny());
    }

    private function userWith(string $permissionKey): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);
        $permission = PermissionModel::query()->firstOrCreate(
            ['key' => $permissionKey],
            ['label' => $permissionKey, 'resource' => 'surveys', 'action' => 'test', 'module' => 'surveys'],
        );
        $role->permissions()->attach($permission->id);
        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * @param  list<string>  $uses
     */
    private function template(string $name, array $uses): SurveyTemplate
    {
        $template = new SurveyTemplate(SurveyTemplateId::generate(), $name, null, SurveyKind::Form, $this->definition(), uses: new TemplateUses($uses));
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $template;
    }

    /**
     * @return Collection<int, SurveyTemplateModel>
     */
    private function models(SurveyTemplate ...$templates): Collection
    {
        return SurveyTemplateModel::query()->whereKey(array_map(static fn (SurveyTemplate $template): string => $template->id()->value, $templates))->get();
    }

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }
}
