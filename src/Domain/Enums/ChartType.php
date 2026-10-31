<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

/**
 * How the results of one question are drawn.
 */
enum ChartType: string
{
    case Bar = 'bar';
    case Pie = 'pie';
    case StackedBar = 'stacked_bar';
    case List = 'list';
}
