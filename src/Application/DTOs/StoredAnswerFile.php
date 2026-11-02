<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\DTOs;

/**
 * A file kept as an answer: the answer holds its id. It is stored under a random name; the name
 * it came with is only kept on named surveys.
 */
final readonly class StoredAnswerFile
{
    public function __construct(
        public string $id,
        public string $surveyId,
        public string $questionId,
        public string $path,
        public string $contentType,
        public int $size,
        public ?string $originalName,
    ) {}
}
