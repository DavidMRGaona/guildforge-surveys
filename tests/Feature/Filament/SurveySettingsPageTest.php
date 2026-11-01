<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Services\SettingsServiceInterface;
use App\Application\Surveys\DTOs\SurveySubjectAttributeValueDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Domain\ValueObjects\TemplateUses;
use Modules\Surveys\Filament\Pages\SurveySettingsPage;
use Modules\Surveys\SurveysServiceProvider;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The surveys settings open with surveys.manage (admins hold every permission), not with the
 * core's modules.configure: the review setting is for whoever manages surveys.
 */
final class SurveySettingsPageTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    private const string MANAGE = 'surveys:surveys.manage';

    private const string RULES = 'subject_types.'.ExampleThingSource::TYPE.'.game_system_templates';

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        ExampleThingSource::reset();
        $this->registerOnAdminPanel([], [SurveySettingsPage::class]);
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_the_provider_registers_the_page(): void
    {
        $this->assertContains(SurveySettingsPage::class, (new SurveysServiceProvider(app()))->registerFilamentPages());
    }

    public function test_only_survey_managers_open_the_page(): void
    {
        $this->actingAs($this->userWith([]));
        $this->assertFalse(SurveySettingsPage::canAccess());
        Livewire::test(SurveySettingsPage::class)->assertForbidden();

        $this->actingAs($this->userWith([self::MANAGE]));
        $this->assertTrue(SurveySettingsPage::canAccess());
        Livewire::test(SurveySettingsPage::class)->assertOk();
    }

    public function test_saving_is_refused_once_the_permission_is_gone(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));
        $page = Livewire::test(SurveySettingsPage::class)->fillForm(['review_required' => true]);

        $this->actingAs($this->userWith([]));
        $page->call('save')->assertForbidden();

        $this->assertFalse(app(SurveySettingsInterface::class)->reviewRequired());
    }

    public function test_it_saves_the_review_setting_and_each_subject_type(): void
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $template = $this->template();
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)
            ->assertSee('Cosa de ejemplo')
            ->fillForm([
                'review_required' => true,
                'subject_types' => [
                    ExampleThingSource::TYPE => [
                        'template_id' => $template->id()->value,
                        'auto_send' => true,
                        'send_delay_hours' => 0,
                        'close_days' => 3,
                        'anonymous' => false,
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = app(SurveySettingsInterface::class);
        $type = $settings->forSubjectType(ExampleThingSource::TYPE);
        $this->assertTrue($settings->reviewRequired());
        $this->assertSame($template->id()->value, $type->templateId);
        $this->assertTrue($type->autoSend);
        $this->assertSame(0, $type->sendDelayHours);
        $this->assertSame(3, $type->closeDays);
        $this->assertFalse($type->anonymous);
    }

    public function test_it_saves_whether_general_surveys_are_announced_by_default(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)
            ->assertFormSet(['announce_in_channels_by_default' => false])
            ->fillForm(['announce_in_channels_by_default' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(app(SurveySettingsInterface::class)->announceInChannelsByDefault());
    }

    public function test_saving_keeps_the_settings_of_a_disabled_module(): void
    {
        app(SettingsServiceInterface::class)->set(
            'module_settings:surveys',
            json_encode(['subject_types' => ['other-thing' => ['auto_send' => true, 'send_delay_hours' => 24]]], JSON_THROW_ON_ERROR),
        );
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)->call('save')->assertHasNoFormErrors();

        $settings = app(SurveySettingsInterface::class);
        $this->assertTrue($settings->forSubjectType('other-thing')->autoSend);
        $this->assertSame(24, $settings->forSubjectType('other-thing')->sendDelayHours);
    }

    public function test_a_new_subject_type_starts_with_twelve_hours(): void
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)
            ->assertFormSet(['subject_types.'.ExampleThingSource::TYPE.'.send_delay_hours' => 12]);
    }

    public function test_the_delay_is_validated_on_the_server(): void
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)
            ->fillForm(['subject_types' => [ExampleThingSource::TYPE => ['auto_send' => true, 'send_delay_hours' => -1]]])
            ->call('save')
            ->assertHasFormErrors(['subject_types.'.ExampleThingSource::TYPE.'.send_delay_hours']);
    }

    public function test_without_sources_it_says_so(): void
    {
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)->assertSee('Ningún módulo ofrece todavía cosas encuestables');
    }

    public function test_values_saved_by_an_older_release_show(): void
    {
        app(SettingsServiceInterface::class)->set(
            'module_settings:surveys',
            json_encode(['min_responses' => 5, 'default_close_days' => 10, 'anonymous_by_default' => false], JSON_THROW_ON_ERROR),
        );
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)->assertFormSet([
            'min_responses' => 5,
            'default_close_days' => 10,
            'anonymous_by_default' => false,
            'review_required' => false,
        ]);
    }

    public function test_each_game_system_the_source_lists_can_have_its_template(): void
    {
        $this->listGameSystems();
        $template = $this->template();
        $this->actingAs($this->userWith([self::MANAGE]));
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(SurveySettingsPage::class)
            ->assertFormFieldVisible(self::RULES)
            ->assertSee('Plantilla por sistema de juego')
            ->fillForm([self::RULES => [
                ['game_system' => 'system-2', 'template_id' => $template->id()->value],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();
        $this->assertSame(
            ['system-2' => $template->id()->value],
            app(SurveySettingsInterface::class)->forSubjectType(ExampleThingSource::TYPE)->gameSystemTemplates,
        );
    }

    public function test_a_game_system_cannot_have_two_templates(): void
    {
        $this->listGameSystems();
        $template = $this->template();
        $this->actingAs($this->userWith([self::MANAGE]));
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(SurveySettingsPage::class)
            ->fillForm([self::RULES => [
                ['game_system' => 'system-1', 'template_id' => $template->id()->value],
                ['game_system' => 'system-1', 'template_id' => $template->id()->value],
            ]])
            ->call('save')
            ->assertHasFormErrors([self::RULES.'.1.game_system']);

        $undoRepeaterFake();
        $this->assertSame([], app(SurveySettingsInterface::class)->forSubjectType(ExampleThingSource::TYPE)->gameSystemTemplates);
    }

    public function test_a_source_that_lists_no_game_systems_shows_no_rules(): void
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)->assertFormFieldHidden(self::RULES);
    }

    public function test_saved_rules_load_and_survive_a_save_even_for_a_game_system_gone_since(): void
    {
        $this->listGameSystems();
        $template = $this->template();
        $rules = [
            ['game_system' => 'system-1', 'template_id' => $template->id()->value],
            ['game_system' => 'system-gone', 'template_id' => $template->id()->value],
        ];
        app(SettingsServiceInterface::class)->set(
            'module_settings:surveys',
            json_encode(['subject_types' => [ExampleThingSource::TYPE => ['game_system_templates' => $rules]]], JSON_THROW_ON_ERROR),
        );
        // What the module loads into its config when it boots
        config()->set('modules.settings.surveys.subject_types', [ExampleThingSource::TYPE => ['game_system_templates' => $rules]]);
        $this->actingAs($this->userWith([self::MANAGE]));
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(SurveySettingsPage::class)
            ->assertFormFieldExists(self::RULES, static fn (Repeater $rules): bool => self::gameSystemField($rules, 1)->getOptions() === [
                'system-1' => 'Juego de ejemplo',
                'system-2' => 'Otro juego de ejemplo',
                'system-gone' => 'Un sistema de juego que ya no existe',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();

        $this->assertSame(
            ['system-1' => $template->id()->value, 'system-gone' => $template->id()->value],
            app(SurveySettingsInterface::class)->forSubjectType(ExampleThingSource::TYPE)->gameSystemTemplates,
        );
    }

    public function test_the_templates_for_each_kind_of_subject_come_first_and_the_rest_under_others(): void
    {
        $this->listGameSystems();
        $forThing = $this->template('De cosas', [ExampleThingSource::TYPE]);
        $general = $this->template('General', [TemplateUses::GENERAL]);
        $unspecified = $this->template('Sin indicar');
        $rules = [['game_system' => 'system-1', 'template_id' => $forThing->id()->value]];
        config()->set('modules.settings.surveys.subject_types', [ExampleThingSource::TYPE => ['game_system_templates' => $rules]]);
        $expected = [
            'Para este tipo' => [$forThing->id()->value => 'De cosas'],
            'Otras' => [$general->id()->value => 'General', $unspecified->id()->value => 'Sin indicar'],
        ];
        $this->actingAs($this->userWith([self::MANAGE]));
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(SurveySettingsPage::class)
            ->assertFormFieldExists('subject_types.'.ExampleThingSource::TYPE.'.template_id', static fn (Select $field): bool => $field->getOptions() === $expected)
            ->assertFormFieldExists(self::RULES, static fn (Repeater $rules): bool => self::ruleField($rules, 0, 'template_id')->getOptions() === $expected)
            ->fillForm(['subject_types' => [ExampleThingSource::TYPE => ['template_id' => $general->id()->value]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $undoRepeaterFake();
        $this->assertSame($general->id()->value, app(SurveySettingsInterface::class)->forSubjectType(ExampleThingSource::TYPE)->templateId);
    }

    public function test_without_templates_for_the_kind_of_subject_they_are_all_listed_as_they_are(): void
    {
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
        $general = $this->template('General', [TemplateUses::GENERAL]);
        $unspecified = $this->template('Sin indicar');
        $this->actingAs($this->userWith([self::MANAGE]));

        Livewire::test(SurveySettingsPage::class)
            ->assertFormFieldExists('subject_types.'.ExampleThingSource::TYPE.'.template_id', static fn (Select $field): bool => $field->getOptions() === [
                $general->id()->value => 'General',
                $unspecified->id()->value => 'Sin indicar',
            ]);
    }

    public function test_the_core_module_settings_page_has_nothing_to_show(): void
    {
        $this->assertSame([], (new SurveysServiceProvider(app()))->getSettingsSchema());
    }

    private static function gameSystemField(Repeater $rules, int $item): Select
    {
        return self::ruleField($rules, $item, 'game_system');
    }

    private static function ruleField(Repeater $rules, int $item, string $name): Select
    {
        $field = array_values($rules->getItems())[$item]->getFlatFields()[$name] ?? null;
        self::assertInstanceOf(Select::class, $field);

        return $field;
    }

    private function listGameSystems(): void
    {
        ExampleThingSource::$gameSystems = [
            new SurveySubjectAttributeValueDTO('system-1', 'Juego de ejemplo'),
            new SurveySubjectAttributeValueDTO('system-2', 'Otro juego de ejemplo'),
        ];
        $this->app->make(SurveySubjectSourceRegistryInterface::class)->register(ExampleThingSource::class);
    }

    /**
     * @param  list<string>  $uses
     */
    private function template(string $name = 'Plantilla de ejemplo', array $uses = []): SurveyTemplate
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            $name,
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
            uses: new TemplateUses($uses),
        );
        $this->app->make(SurveyTemplateRepositoryInterface::class)->save($template);

        return $template;
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    private function userWith(array $permissionKeys): UserModel
    {
        $role = RoleModel::query()->create(['name' => 'tester_'.uniqid(), 'display_name' => 'Permissions tester']);

        foreach ($permissionKeys as $key) {
            $permission = PermissionModel::query()->firstOrCreate(
                ['key' => $key],
                ['label' => $key, 'resource' => 'surveys', 'action' => 'manage', 'module' => 'surveys'],
            );
            $role->permissions()->attach($permission->id);
        }

        $user = UserModel::factory()->editor()->create();
        $user->roles()->attach($role->id);

        return $user;
    }
}
