<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Support;

use Filament\Support\Colors\Color;

/**
 * The series colours of the panel's charts, in a fixed order, so results and trends match.
 */
final class ChartPalette
{
    public static function color(int $index): string
    {
        $palette = [Color::Amber[500], Color::Sky[500], Color::Emerald[500], Color::Rose[500], Color::Violet[500], Color::Lime[500], Color::Orange[500], Color::Teal[500]];

        return $palette[$index % count($palette)];
    }
}
