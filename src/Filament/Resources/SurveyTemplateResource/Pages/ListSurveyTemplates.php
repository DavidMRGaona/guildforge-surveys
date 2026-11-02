<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages;

use App\Filament\Concerns\HasKnowledgeGuideAction;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;

final class ListSurveyTemplates extends ListRecords
{
    use HasKnowledgeGuideAction;

    protected static string $resource = SurveyTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            ...array_filter([$this->knowledgeGuideAction('surveys.panel.templates')]),
        ];
    }
}
