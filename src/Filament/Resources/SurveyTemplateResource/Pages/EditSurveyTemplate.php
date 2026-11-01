<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Surveys\Domain\Repositories\SurveyTemplateRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\Definition\SurveyDefinition;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Concerns\HandlesDefinitionErrors;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;
use Modules\Surveys\Filament\Support\DefinitionFormState;

final class EditSurveyTemplate extends EditRecord
{
    use HandlesDefinitionErrors;

    protected static string $resource = SurveyTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $definition = is_array($data['definition'] ?? null) ? $data['definition'] : ['sections' => []];
        $data['sections'] = DefinitionFormState::fromDefinition(SurveyDefinition::fromArray($definition));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $templates = app(SurveyTemplateRepositoryInterface::class);
        $template = $templates->findOrFail(SurveyTemplateId::fromString((string) $record->getKey()));
        $sections = is_array($data['sections'] ?? null) ? $data['sections'] : [];

        $this->withDefinitionErrors(static function () use ($template, $sections): void {
            $template->replaceDefinition(DefinitionFormState::toDefinition($sections), new SurveyDefinitionValidator);
        });
        $template->rename((string) $data['name'], is_string($data['description'] ?? null) ? $data['description'] : null);
        $template->changeUses(SurveyTemplateResource::usesFrom($data));
        $templates->save($template);

        return $record->refresh();
    }
}
