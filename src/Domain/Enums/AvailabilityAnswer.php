<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

/**
 * What each person says about a proposed slot of an availability survey.
 */
enum AvailabilityAnswer: string
{
    case Yes = 'yes';
    case IfNeeded = 'if_needed';
    case No = 'no';
}
