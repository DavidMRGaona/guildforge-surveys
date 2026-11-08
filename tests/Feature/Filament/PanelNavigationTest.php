<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Filament;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Support\PanelNavigationGroup;
use Modules\Surveys\Filament\Pages\SurveySettingsPage;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\SurveysServiceProvider;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The panel menu is grouped by task (core#409): surveys live in «Comunicación» with their
 * templates and trends under them, and the settings join the «Configuración» cluster.
 */
final class PanelNavigationTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerOnAdminPanel(
            [SurveyResource::class, SurveyTemplateResource::class],
            [SurveyTrendsPage::class, SurveySettingsPage::class],
        );
        $this->actingAsPanelAdmin();
    }

    public function test_surveys_sit_in_the_communication_group_in_their_own_slot(): void
    {
        $this->assertSame(PanelNavigationGroup::Communication->getLabel(), SurveyResource::getNavigationGroup());
        $this->assertSame(20, SurveyResource::getNavigationSort());
        $this->assertSame('Encuestas', SurveyResource::getNavigationLabel());
        $this->assertNull(SurveyResource::getNavigationParentItem());
    }

    public function test_templates_are_a_child_of_surveys_in_the_same_group(): void
    {
        $this->assertSame(PanelNavigationGroup::Communication->getLabel(), SurveyTemplateResource::getNavigationGroup());
        $this->assertSame(SurveyResource::getNavigationLabel(), SurveyTemplateResource::getNavigationParentItem());
        $this->assertSame('Plantillas', SurveyTemplateResource::getNavigationLabel());
    }

    public function test_trends_are_a_child_of_surveys_in_the_same_group(): void
    {
        $this->assertSame(PanelNavigationGroup::Communication->getLabel(), SurveyTrendsPage::getNavigationGroup());
        $this->assertSame(SurveyResource::getNavigationLabel(), SurveyTrendsPage::getNavigationParentItem());
        $this->assertSame('Tendencias', SurveyTrendsPage::getNavigationLabel());
    }

    public function test_the_settings_join_the_settings_cluster(): void
    {
        $this->assertSame(SettingsCluster::class, SurveySettingsPage::getCluster());
        $this->assertSame(120, SurveySettingsPage::getNavigationSort());
        $this->assertSame('Encuestas', SurveySettingsPage::getNavigationLabel());
        $this->assertNull(SurveySettingsPage::getNavigationGroup());
        $this->assertSame('Sistema', SettingsCluster::getNavigationGroup());
        $this->assertStringStartsWith('/admin/configuracion/', (string) parse_url(SurveySettingsPage::getUrl(), PHP_URL_PATH));
    }

    public function test_the_module_declares_no_navigation_group_of_its_own(): void
    {
        $this->assertSame([], (new SurveysServiceProvider($this->app))->registerNavigationGroups());
    }

    public function test_the_panel_menu_shows_surveys_under_the_communication_group(): void
    {
        $this->get(SurveyResource::getUrl())
            ->assertOk()
            ->assertSeeInOrder([PanelNavigationGroup::Communication->getLabel(), 'Encuestas']);
    }
}
