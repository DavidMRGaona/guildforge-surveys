<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Export;

use Generator;

final readonly class ExportTable
{
    /**
     * @param  list<string>  $headers
     * @param  Generator<int, list<string|int|null>>  $rows  read once, in batches
     * @param  string  $fileName  without extension
     */
    public function __construct(
        public array $headers,
        public Generator $rows,
        public string $fileName,
    ) {}
}
