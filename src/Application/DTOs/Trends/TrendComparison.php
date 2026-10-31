<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

/**
 * What the series of a trend compare. Organizers only in the panel: the site shows each
 * organizer their own surveys.
 */
enum TrendComparison: string
{
    case None = 'none';
    case Organizer = 'organizer';
    case GameSystem = 'game_system';
    case SubjectType = 'subject_type';
}
