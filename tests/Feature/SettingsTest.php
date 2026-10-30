<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature;

use App\Filament\Pages\ModuleSettingsPage;
use Livewire\Livewire;
use Modules\Surveys\Infrastructure\Services\SurveySettings;
use Tests\Support\Modules\InteractsWithModulePanel;
use Tests\Support\Modules\ModuleTestCase;

final class SettingsTest extends ModuleTestCase
{
    use InteractsWithModulePanel;

    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_release_defaults(): void
    {
        $settings = new SurveySettings;

        $this->assertSame(3, $settings->minResponses());
        $this->assertSame(7, $settings->defaultCloseDays());
        $this->assertTrue($settings->anonymousByDefault());
    }

    public function test_saved_values_win_and_zero_means_no_minimum(): void
    {
        config()->set('modules.settings.surveys', ['min_responses' => '0', 'default_close_days' => '14', 'anonymous_by_default' => false]);

        $settings = new SurveySettings;

        $this->assertSame(0, $settings->minResponses());
        $this->assertSame(14, $settings->defaultCloseDays());
        $this->assertFalse($settings->anonymousByDefault());
    }

    public function test_out_of_range_values_fall_back(): void
    {
        config()->set('modules.settings.surveys', ['min_responses' => '-1', 'default_close_days' => '0']);

        $settings = new SurveySettings;

        $this->assertSame(0, $settings->minResponses());
        $this->assertSame(7, $settings->defaultCloseDays());
    }

    public function test_the_settings_form_offers_the_three_settings(): void
    {
        $this->actingAsPanelAdmin();

        Livewire::test(ModuleSettingsPage::class, ['module' => 'surveys'])
            ->assertOk()
            ->assertFormFieldExists('min_responses')
            ->assertFormFieldExists('default_close_days')
            ->assertFormFieldExists('anonymous_by_default');
    }
}
