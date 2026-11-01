<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Feature;

use Modules\Surveys\SurveysServiceProvider;
use Tests\Support\Modules\ModuleTestCase;

final class ModuleBootsTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_module_is_enabled_and_its_provider_is_loaded(): void
    {
        $module = $this->findModule('surveys');

        $this->assertNotNull($module);
        $this->assertTrue($module->isEnabled());
        $this->assertNotNull($this->app->getProvider(SurveysServiceProvider::class));
    }

    public function test_manifest_requires_the_core_with_the_survey_contract(): void
    {
        /** @var array{requires: array{core: string}, storesUtc: bool} $manifest */
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../module.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('^3.32', $manifest['requires']['core']);
        $this->assertTrue($manifest['storesUtc']);
    }
}
