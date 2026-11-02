<?php

declare(strict_types=1);

namespace Modules\Surveys\Application\Contracts;

use DateTimeImmutable;
use Modules\Surveys\Application\DTOs\IncomingFile;
use Modules\Surveys\Application\DTOs\StoredAnswerFile;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * Where the files sent as answers are kept: private storage, never a public directory.
 */
interface AnswerFileStoreInterface
{
    /**
     * The content type read from the file itself, or null when it cannot be read.
     */
    public function contentType(IncomingFile $file): ?string;

    /**
     * Keeps the file under a random name. Images are written again, which leaves out their
     * metadata (EXIF, location, camera); anything else is kept as sent.
     *
     * @return StoredAnswerFile|null null when the file is not what its contents claim (an image
     *                               that cannot be read)
     */
    public function store(SurveyId $surveyId, string $fileId, IncomingFile $file, string $contentType, bool $keepName): ?StoredAnswerFile;

    /**
     * Removes files stored for a response that was not kept.
     *
     * @param  list<StoredAnswerFile>  $files
     */
    public function discard(array $files): void;

    /**
     * The file's contents as a stream, or null when it is no longer there.
     *
     * @return resource|null
     */
    public function readStream(StoredAnswerFile $file): mixed;

    /**
     * Removes every file of a survey.
     */
    public function deleteForSurvey(SurveyId $surveyId): void;

    /**
     * Removes the stored files no answer points at (a response that failed after storing them),
     * when they are older than $before so a response being stored right now keeps its own.
     *
     * @return int how many were removed
     */
    public function pruneOrphans(DateTimeImmutable $before): int;
}
