<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs\Results;

use Modules\Surveys\Domain\ValueObjects\Definition\Option;

final readonly class GridResultDTO
{
    /**
     * @param  list<Option>  $rows
     * @param  list<Option>  $columns
     * @param  array<string, array<string, int>>  $counts  row id => column id => answers, every cell present
     */
    public function __construct(
        public array $rows,
        public array $columns,
        public array $counts,
    ) {}
}
