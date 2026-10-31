<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Export;

/**
 * Answers are written by anyone the survey reaches: a spreadsheet must never run one as a
 * formula (CSV injection), so such values get a leading single quote.
 */
final class FormulaGuard
{
    public static function neutralise(string|int|null $value): string|int|null
    {
        if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
