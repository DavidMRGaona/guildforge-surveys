<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Trends;

use Modules\Surveys\Domain\Enums\QuestionType;

/**
 * The trend of one keyed question of a template, or of one row of a keyed grid
 * ("<key>.<row id>"). Scales trend their mean; choices and grid rows, their shares.
 */
final readonly class QuestionTrendDTO
{
    public const string MEAN = 'mean';

    public const string SHARES = 'shares';

    /** Types with no trend: free text, dates and times. */
    public const string NONE = 'none';

    /** The only "period" of choices compared by series: the whole range. */
    public const string TOTAL = 'total';

    /** Answers with options the template no longer has. */
    public const string PREVIOUS = '_previous';

    /**
     * @param  list<array{key: string, label: string}>  $periods
     * @param  list<array{id: string, label: string}>  $options  options or grid columns, for shares
     * @param  list<TrendSeriesDTO>  $series
     */
    public function __construct(
        public string $key,
        public string $title,
        public QuestionType $type,
        public string $measure,
        public ?int $min = null,
        public ?int $max = null,
        public array $periods = [],
        public array $options = [],
        public array $series = [],
    ) {}
}
