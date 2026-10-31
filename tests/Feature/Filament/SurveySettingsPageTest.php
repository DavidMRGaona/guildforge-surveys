<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Application\Services\SettingsServiceInterface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
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

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel([], [SurveySettingsPage::class]);
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

    public function test_the_core_module_settings_page_has_nothing_to_show(): void
    {
        $this->assertSame([], (new SurveysServiceProvider(app()))->getSettingsSchema());
    }

    private function template(): SurveyTemplate
    {
        $template = new SurveyTemplate(
            SurveyTemplateId::generate(),
            'Plantilla de ejemplo',
            null,
            SurveyKind::Form,
            SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()),
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
