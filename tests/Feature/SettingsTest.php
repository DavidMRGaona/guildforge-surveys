<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature;

use Modules\Surveys\Infrastructure\Services\SurveySettings;
use Tests\Support\Modules\ModuleTestCase;

final class SettingsTest extends ModuleTestCase
{
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

    public function test_channel_announcements_are_off_by_default(): void
    {
        $this->assertFalse((new SurveySettings)->announceInChannelsByDefault());

        config()->set('modules.settings.surveys.announce_in_channels_by_default', true);

        $this->assertTrue((new SurveySettings)->announceInChannelsByDefault());
    }

    public function test_review_is_off_by_default(): void
    {
        $this->assertFalse((new SurveySettings)->reviewRequired());

        config()->set('modules.settings.surveys.review_required', true);

        $this->assertTrue((new SurveySettings)->reviewRequired());
    }

    public function test_subject_type_settings_fall_back_to_the_global_defaults(): void
    {
        config()->set('modules.settings.surveys', ['default_close_days' => '10', 'anonymous_by_default' => false]);

        $type = (new SurveySettings)->forSubjectType('example-thing');

        $this->assertNull($type->templateId);
        $this->assertFalse($type->autoSend);
        $this->assertSame(12, $type->sendDelayHours);
        $this->assertSame(10, $type->closeDays);
        $this->assertFalse($type->anonymous);
    }

    public function test_subject_type_settings_read_what_the_panel_saved(): void
    {
        config()->set('modules.settings.surveys.subject_types', [
            'example-thing' => [
                'template_id' => '01960000-0000-7000-8000-000000000001',
                'auto_send' => true,
                'send_delay_hours' => '48',
                'close_days' => '3',
                'anonymous' => true,
            ],
        ]);

        $type = (new SurveySettings)->forSubjectType('example-thing');

        $this->assertSame('01960000-0000-7000-8000-000000000001', $type->templateId);
        $this->assertTrue($type->autoSend);
        $this->assertSame(48, $type->sendDelayHours);
        $this->assertSame(3, $type->closeDays);
        $this->assertTrue($type->anonymous);
    }

    public function test_a_zero_delay_sends_on_end(): void
    {
        config()->set('modules.settings.surveys.subject_types.example-thing', ['send_delay_hours' => 0]);

        $this->assertSame(0, (new SurveySettings)->forSubjectType('example-thing')->sendDelayHours);
    }

    public function test_broken_subject_type_values_fall_back(): void
    {
        config()->set('modules.settings.surveys.subject_types.example-thing', [
            'template_id' => '',
            'send_delay_hours' => '-3',
            'close_days' => '0',
        ]);

        $type = (new SurveySettings)->forSubjectType('example-thing');

        $this->assertNull($type->templateId);
        $this->assertSame(12, $type->sendDelayHours);
        $this->assertSame(7, $type->closeDays);
    }
}
