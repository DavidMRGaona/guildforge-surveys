<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

/**
 * A poll is a one-question form with its own presentation: both share the data model.
 */
enum SurveyKind: string
{
    case Form = 'form';
    case Poll = 'poll';
}
