<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

/**
 * A file someone sent to answer a file question, still where the upload left it. Its name and
 * size come from the request: only the contents say what it is.
 */
final readonly class IncomingFile
{
    public function __construct(
        public string $questionId,
        public string $path,
        public string $clientName,
        public int $size,
    ) {}
}
