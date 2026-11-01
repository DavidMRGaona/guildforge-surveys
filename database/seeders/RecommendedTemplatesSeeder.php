<?php

declare(strict_types=1);

namespace Modules\Surveys\Database\Seeders;

use App\Application\Modules\Services\ModuleManagerServiceInterface;
use App\Application\Modules\Services\ModuleSettingsStoreInterface;
use App\Domain\Modules\ValueObjects\ModuleName;
use Illuminate\Database\Seeder;
use Modules\Surveys\Application\Templates\RecommendedTemplate;
use Modules\Surveys\Application\Templates\RecommendedTemplates;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

/**
 * Ships the recommended templates. The core runs a module's seeders once per installation,
 * production included, so this only adds what is missing, found by catalog key: a template the
 * association renamed or edited is never touched, and one it deleted does not come back.
 *
 * A template created now becomes the default of its kind of subject when that kind has none
 * yet; a choice already made, including "none" for a template that was already there, stays.
 */
final class RecommendedTemplatesSeeder extends Seeder
{
    /**
     * Enabling the module in a test runs every seeder, and module tests expect no templates.
     * Only the tests of this seeder turn it on.
     */
    public static bool $seedInTests = false;

    public function __construct(
        private readonly RecommendedTemplates $catalog,
        private readonly SurveyDefinitionValidator $validator,
        private readonly ModuleSettingsStoreInterface $settingsStore,
        private readonly ModuleManagerServiceInterface $moduleManager,
    ) {}

    public function run(): void
    {
        if (app()->runningUnitTests() && ! self::$seedInTests) {
            return;
        }

        $this->registerTranslations();

        $defaults = [];
        foreach ($this->catalog->all() as $template) {
            $id = $this->createIfMissing($template);
            if ($id !== null && $template->subjectType !== null) {
                $defaults[$template->subjectType] = $id;
            }
        }

        $this->setDefaults($defaults);
    }

    /**
     * @return string|null the id of the template, when it was created now
     */
    private function createIfMissing(RecommendedTemplate $template): ?string
    {
        if (SurveyTemplateModel::query()->where('catalog_key', $template->catalogKey)->exists()) {
            return null;
        }

        $this->validator->assertValid($template->definition, SurveyKind::Form);

        return SurveyTemplateModel::query()->create([
            'catalog_key' => $template->catalogKey,
            'name' => $template->name,
            'description' => $template->description,
            'kind' => SurveyKind::Form->value,
            'definition' => $template->definition->toArray(),
        ])->id;
    }

    /**
     * Over what the panel saved, so every other setting stays as it is.
     *
     * @param  array<string, string>  $templateIds  kind of subject => template id
     */
    private function setDefaults(array $templateIds): void
    {
        $settings = $this->settingsStore->stored('surveys');
        $types = is_array($settings['subject_types'] ?? null) ? $settings['subject_types'] : [];
        $changed = false;

        foreach ($templateIds as $type => $templateId) {
            $entry = is_array($types[$type] ?? null) ? $types[$type] : [];
            $current = $entry['template_id'] ?? null;
            if (is_string($current) && $current !== '') {
                continue;
            }

            $entry['template_id'] = $templateId;
            $types[$type] = $entry;
            $changed = true;
        }

        if ($changed) {
            $settings['subject_types'] = $types;
            $this->moduleManager->updateSettings(new ModuleName('surveys'), $settings);
        }
    }

    /**
     * On install the seeders run before the module's provider registers its translations.
     */
    private function registerTranslations(): void
    {
        $translator = app('translator');
        if (! array_key_exists('surveys', $translator->getLoader()->namespaces())) {
            $translator->addNamespace('surveys', dirname(__DIR__, 2).'/lang');
        }
    }
}
