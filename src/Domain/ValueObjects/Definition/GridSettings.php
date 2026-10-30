<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

final readonly class GridSettings
{
    /**
     * @param  list<Option>  $rows
     * @param  list<Option>  $columns
     */
    public function __construct(
        public array $rows,
        public array $columns,
    ) {}

    /**
     * @return array{rows: list<array{id: string, label: string}>, columns: list<array{id: string, label: string}>}
     */
    public function toArray(): array
    {
        return [
            'rows' => array_map(static fn (Option $row): array => $row->toArray(), $this->rows),
            'columns' => array_map(static fn (Option $column): array => $column->toArray(), $this->columns),
        ];
    }
}
