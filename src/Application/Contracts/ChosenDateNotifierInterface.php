<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use Modules\Surveys\Domain\Entities\Survey;

/**
 * Tells the people of an availability survey which date was fixed.
 */
interface ChosenDateNotifierInterface
{
    public function dateChosen(Survey $survey): void;
}
