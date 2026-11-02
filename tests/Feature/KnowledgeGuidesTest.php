<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature;

use Modules\Surveys\Filament\Pages\SurveySettingsPage;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

/**
 * The module's help guides pass the knowledge base lint, so CI blocks a broken one.
 */
final class KnowledgeGuidesTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_guides_are_valid(): void
    {
        // The panel guides link to the module's panel pages, routed only once they are on the panel
        $this->registerOnAdminPanel(
            [SurveyResource::class, SurveyTemplateResource::class],
            [SurveySettingsPage::class, SurveyTrendsPage::class],
        );

        $this->assertModuleKnowledgeGuidesAreValid('surveys');
    }
}
