<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\CreateSurveyTemplate;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\EditSurveyTemplate;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
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

        $this->registerOnAdminPanel([SurveyTemplateResource::class]);
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

    private function definition(): SurveyDefinition
    {
        return SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition());
    }
}
