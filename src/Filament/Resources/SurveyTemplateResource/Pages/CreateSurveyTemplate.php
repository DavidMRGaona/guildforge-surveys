<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyTemplateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Surveys\Filament\Resources\SurveyTemplateResource;

final class CreateSurveyTemplate extends CreateRecord
{
    protected static string $resource = SurveyTemplateResource::class;
}
