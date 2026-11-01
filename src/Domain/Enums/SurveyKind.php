<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

/**
 * A poll and an availability survey are one-question forms with their own presentation: all
 * share the data model.
 */
enum SurveyKind: string
{
    case Form = 'form';
    case Poll = 'poll';
    case Availability = 'availability';

    /**
     * An availability survey proposes dates that only make sense once, so it never becomes a template.
     */
    public function allowsTemplates(): bool
    {
        return $this !== self::Availability;
    }
}
