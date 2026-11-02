<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature\Infrastructure\Knowledge;

use App\Application\Knowledge\Services\KnowledgeQueryServiceInterface;
use App\Domain\Knowledge\Enums\GuideAudience;
use App\Domain\Knowledge\ValueObjects\GuideId;
use App\Filament\Pages\KnowledgeBasePage;
use App\Infrastructure\Persistence\Eloquent\Models\PermissionModel;
use App\Infrastructure\Persistence\Eloquent\Models\RoleModel;
use App\Infrastructure\Persistence\Eloquent\Models\UserModel;
use Livewire\Livewire;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Filament\Pages\SurveySettingsPage;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\EditSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ListSurveys;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurvey;
use Modules\Surveys\Filament\Resources\SurveyResource\Pages\ViewSurveyResults;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages\ListSurveyTemplates;
use Modules\Surveys\Tests\Support\AnswersExampleSurveys;
use Modules\Surveys\Tests\Support\ExampleThingSource;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * Who sees each of the module's guides, and the help button on its panel pages.
 */
final class SurveyGuidesTest extends ModuleTestCase
{
    use AnswersExampleSurveys;
    use InteractsWithModulePanel;

    private const array SITE_GUIDES = [
        'surveys.answer-survey',
        'surveys.organizer-end-survey',
        'surveys.organizer-polls',
        'surveys.organizer-results',
    ];

    private const array ORGANIZER_GUIDES = [
        'surveys.organizer-end-survey',
        'surveys.organizer-polls',
        'surveys.organizer-results',
    ];

    private const array PANEL_GUIDES = [
        'surveys.panel.create-survey',
        'surveys.panel.open-share-close',
        'surveys.panel.templates',
        'surveys.panel.settings',
        'surveys.panel.results',
        'surveys.panel.trends',
        'surveys.panel.review',
    ];

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useUtcStorage();
        $this->registerOnAdminPanel(
            [SurveyResource::class, SurveyTemplateResource::class],
            [SurveySettingsPage::class, SurveyTrendsPage::class],
        );
        $this->createExamplePeople();
        $this->artisanCommand('knowledge:sync')->assertSuccessful()->run();
    }

    protected function tearDown(): void
    {
        ExampleThingSource::reset();

        parent::tearDown();
    }

    public function test_an_admin_sees_every_panel_guide_rendered_without_raw_markup(): void
    {
        $admin = UserModel::factory()->admin()->create();

        foreach (self::PANEL_GUIDES as $id) {
            $guide = $this->queries()->guide($admin, GuideAudience::Panel, GuideId::fromString($id));
            $this->assertNotNull($guide, $id);
            $this->assertStringNotContainsString('[[', $guide->html, $id);
            $this->assertStringNotContainsString('{{', $guide->html, $id);
            $this->assertStringNotContainsString('[!', $guide->html, $id);
        }
    }

    public function test_each_permission_shows_only_its_panel_guides(): void
    {
        $this->assertSame(
            ['surveys.panel.create-survey', 'surveys.panel.open-share-close', 'surveys.panel.settings', 'surveys.panel.templates'],
            $this->visiblePanelGuides($this->userWith('surveys:surveys.manage')),
        );
        $this->assertSame(
            ['surveys.panel.results', 'surveys.panel.trends'],
            $this->visiblePanelGuides($this->userWith('surveys:surveys.view_results')),
        );
        $this->assertSame(
            ['surveys.panel.review'],
            $this->visiblePanelGuides($this->userWith('surveys:surveys.moderate')),
        );
    }

    public function test_organizer_guides_need_surveys_of_something_the_person_organizes(): void
    {
        [$organizer, $player, $newOrganizer] = $this->people;
        $this->surveyOrganizedBy($organizer);
        // Manages the thing too, but no survey names them yet
        ExampleThingSource::$managers = [$organizer->id, $newOrganizer->id];

        $this->assertSame(self::SITE_GUIDES, $this->visibleSiteGuides($organizer));
        $this->assertSame(['surveys.answer-survey'], $this->visibleSiteGuides($player));
        $this->assertSame(['surveys.answer-survey'], $this->visibleSiteGuides($newOrganizer));
    }

    public function test_every_site_guide_renders_without_raw_markup(): void
    {
        [$organizer] = $this->people;
        $this->surveyOrganizedBy($organizer);

        foreach (self::ORGANIZER_GUIDES as $id) {
            $guide = $this->queries()->guide($organizer, GuideAudience::Site, GuideId::fromString($id));
            $this->assertNotNull($guide, $id);
            $this->assertStringNotContainsString('[[', $guide->html, $id);
            $this->assertStringNotContainsString('{{', $guide->html, $id);
        }
    }

    public function test_the_panel_pages_open_their_guide(): void
    {
        $this->actingAsPanelAdmin();
        [$id] = $this->openForm(anonymous: false);
        $record = ['record' => $id->value];

        $pages = [
            [ListSurveys::class, [], 'surveys.panel.create-survey'],
            [EditSurvey::class, $record, 'surveys.panel.open-share-close'],
            [ViewSurvey::class, $record, 'surveys.panel.open-share-close'],
            [ViewSurveyResults::class, $record, 'surveys.panel.results'],
            [ListSurveyTemplates::class, [], 'surveys.panel.templates'],
            [SurveySettingsPage::class, [], 'surveys.panel.settings'],
            [SurveyTrendsPage::class, [], 'surveys.panel.trends'],
        ];

        foreach ($pages as [$page, $parameters, $guide]) {
            Livewire::test($page, $parameters)
                ->assertActionVisible('contextualHelp')
                ->assertActionHasUrl('contextualHelp', KnowledgeBasePage::getUrl(['guia' => $guide]));
        }
    }

    public function test_no_help_button_without_the_guide_permission(): void
    {
        // A moderator reads the list, but its guide is about creating surveys (surveys.manage)
        $this->actingAs($this->userWith('surveys:surveys.moderate'));

        Livewire::test(ListSurveys::class)->assertActionDoesNotExist('contextualHelp');
    }

    /**
     * An open survey of the example thing, whose snapshot names the person as its organizer.
     */
    private function surveyOrganizedBy(UserModel $organizer): void
    {
        ExampleThingSource::$organizerIds = [$organizer->id];
        ExampleThingSource::$managers = [$organizer->id];
        $this->openExample(SurveyKind::Form, SurveyDefinition::fromArray(SurveyDefinitionTest::everyTypeDefinition()), false, ResultsSettings::defaultsFor(SurveyKind::Form));
    }

    /**
     * @return list<string>
     */
    private function visiblePanelGuides(UserModel $user): array
    {
        $ids = array_values(array_filter(
            $this->queries()->visibleIds($user, GuideAudience::Panel),
            static fn (string $id): bool => str_starts_with($id, 'surveys.'),
        ));
        sort($ids);

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function visibleSiteGuides(UserModel $user): array
    {
        $ids = array_values(array_filter(
            $this->queries()->visibleIds($user, GuideAudience::Site),
            static fn (string $id): bool => str_starts_with($id, 'surveys.'),
        ));
        sort($ids);

        return $ids;
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

    private function queries(): KnowledgeQueryServiceInterface
    {
        return $this->app->make(KnowledgeQueryServiceInterface::class);
    }
}
