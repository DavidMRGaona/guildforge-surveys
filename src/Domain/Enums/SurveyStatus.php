<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

enum SurveyStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
}
