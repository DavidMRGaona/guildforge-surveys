<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyKind;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\Services\SurveyDefinitionValidator;
use Modules\Surveys\Domain\ValueObjects\ResultsSettings;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Concerns\HandlesDefinitionErrors;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Support\DefinitionFormState;
use Modules\Surveys\Filament\Support\SurveyFormData;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;

final class CreateSurvey extends CreateRecord
{
    use HandlesDefinitionErrors;
    use HasKnowledgeGuideAction;

    protected static string $resource = SurveyResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return array_filter([$this->knowledgeGuideAction('surveys.panel.create-survey')]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $survey = $this->withDefinitionErrors(static function () use ($data): Survey {
            $definition = DefinitionFormState::fromFormData($data);
            (new SurveyDefinitionValidator)->assertValid($definition, SurveyKind::Form);
            $templateId = is_string($data['template_id'] ?? null) && $data['template_id'] !== '' ? $data['template_id'] : null;

            return new Survey(
                id: SurveyId::generate(),
                kind: SurveyKind::Form,
                title: (string) $data['title'],
                description: SurveyFormData::nullableText($data, 'description'),
                definition: $definition,
                anonymous: (bool) ($data['anonymous'] ?? true),
                results: SurveyFormData::results($data, ResultsSettings::defaultsFor(SurveyKind::Form)),
                audienceType: SurveyFormData::audienceType($data),
                audienceRoles: SurveyFormData::audienceRoles($data),
                publicLinkEnabled: (bool) ($data['public_link_enabled'] ?? false),
                templateId: $templateId === null ? null : SurveyTemplateId::fromString($templateId),
                closesAt: SurveyFormData::closesAt($data),
                reminderHoursBefore: SurveyFormData::reminderHoursBefore($data),
                createdBy: is_string(auth()->id()) ? auth()->id() : null,
                publicLinkMode: SurveyFormData::publicLinkMode($data),
                announceInChannels: (bool) ($data['announce_in_channels'] ?? false),
                closingSummary: (bool) ($data['closing_summary'] ?? false),
            );
        });

        app(SurveyRepositoryInterface::class)->save($survey);

        return SurveyModel::query()->findOrFail($survey->id()->value);
    }
}
