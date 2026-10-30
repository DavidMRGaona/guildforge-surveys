<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Resources\SurveyResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\Surveys\Filament\Resources\SurveyResource;

final class CreateSurvey extends CreateRecord
{
    protected static string $resource = SurveyResource::class;
}
