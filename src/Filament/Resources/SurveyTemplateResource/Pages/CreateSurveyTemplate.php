<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Surveys\Domain\Entities\SurveyTemplate;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Concerns\HandlesDefinitionErrors;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyTemplateModel;

final class CreateSurveyTemplate extends CreateRecord
{
    use HandlesDefinitionErrors;

    protected static string $resource = SurveyTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];

        $template = $this->withDefinitionErrors(static function () use ($data, $sections): SurveyTemplate {
            $kind = SurveyKind::from((string) $data['kind']);
            $definition = DefinitionFormState::toDefinition($sections);
            (new SurveyDefinitionValidator)->assertValid($definition, $kind);

            return new SurveyTemplate(
                id: SurveyTemplateId::generate(),
                name: (string) $data['name'],
                description: is_string($data['description'] ?? null) ? $data['description'] : null,
                kind: $kind,
                definition: $definition,
                createdBy: is_string(auth()->id()) ? auth()->id() : null,
                uses: SurveyTemplateResource::usesFrom($data),
            );
        });

        app(SurveyTemplateRepositoryInterface::class)->save($template);

        return SurveyTemplateModel::query()->findOrFail($template->id()->value);
    }
}
