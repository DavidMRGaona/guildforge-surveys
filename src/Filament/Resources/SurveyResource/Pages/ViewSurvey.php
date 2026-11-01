<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyResource\Pages;

use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Modules\Surveys\Filament\Actions\SurveyActions;
use Modules\Surveys\Filament\Resources\SurveyResource;
use Modules\Surveys\Filament\Support\SurveyFormData;

/**
 * The survey's form, read-only: where a moderator without surveys.manage reads what they review.
 */
final class ViewSurvey extends ViewRecord
{
    protected static string $resource = SurveyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SurveyActions::approve()->after(fn () => $this->refreshFormData(['status'])),
            SurveyActions::reject()->after(fn () => $this->refreshFormData(['status'])),
            SurveyActions::results(),
            SurveyActions::renewPublicLink(),
            SurveyActions::duplicate(),
            SurveyActions::saveAsTemplate(),
            EditAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return SurveyFormData::forFill($data);
    }
}
