<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects\Definition;

use Modules\Surveys\Domain\Enums\FileKind;

/**
 * What a file question takes: the kinds of file and the largest size, in megabytes.
 */
final readonly class FileSettings
{
    /**
     * @param  list<FileKind>  $accept
     */
    public function __construct(
        public array $accept,
        public int $maxMb,
    ) {}

    public function accepts(FileKind $kind): bool
    {
        return in_array($kind, $this->accept, true);
    }

    /**
     * @return array{accept: list<string>, max_mb: int}
     */
    public function toArray(): array
    {
        return [
            'accept' => array_map(static fn (FileKind $kind): string => $kind->value, $this->accept),
            'max_mb' => $this->maxMb,
        ];
    }
}
