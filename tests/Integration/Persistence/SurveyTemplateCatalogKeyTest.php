<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Integration\Persistence;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;
use Modules\Surveys\Tests\Unit\Domain\ValueObjects\Definition\SurveyDefinitionTest;
use Tests\Support\Modules\ModuleTestCase;

final class SurveyTemplateCatalogKeyTest extends ModuleTestCase
{
    protected ?string $moduleName = 'surveys';

    protected bool $autoEnableModule = true;

    public function test_saving_a_template_from_the_panel_keeps_its_catalog_key(): void
    {
        $model = $this->template('game_table');
        $templates = $this->app->make(SurveyTemplateRepositoryInterface::class);

        $template = $templates->findOrFail(SurveyTemplateId::fromString($model->id));
        $template->rename('Partida de rol del club', 'Nuestra versión');
        $templates->save($template);

        $model->refresh();
        $this->assertSame('game_table', $model->catalog_key);
        $this->assertSame('Partida de rol del club', $model->name);
    }

    public function test_catalog_keys_are_unique(): void
    {
        $this->template('game_table');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->template('game_table');
    }

    public function test_templates_made_in_the_panel_have_no_catalog_key(): void
    {
        $this->template(null);
        $this->template(null);

        $this->assertSame(2, SurveyTemplateModel::query()->whereNull('catalog_key')->count());
    }

    private function template(?string $catalogKey): SurveyTemplateModel
    {
        return SurveyTemplateModel::query()->create([
            'name' => 'Plantilla',
            'kind' => SurveyKind::Form->value,
            'definition' => SurveyDefinitionTest::everyTypeDefinition(),
            'catalog_key' => $catalogKey,
        ]);
    }
}
