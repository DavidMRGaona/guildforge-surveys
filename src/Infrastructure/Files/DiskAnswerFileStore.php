<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Files;

use DateTimeImmutable;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\EncoderInterface;
use Modules\Surveys\Application\Contracts\AnswerFileStoreInterface;
use Modules\Surveys\Application\DTOs\IncomingFile;
use Modules\Surveys\Application\DTOs\StoredAnswerFile;
use Modules\Surveys\Domain\Enums\FileKind;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyAnswerFileModel;
use Throwable;

/**
 * Keeps answer files on a private disk (the files_disk setting, the private "local" disk by
 * default), under surveys/<survey id>/<file id>.<extension>. Images are decoded and encoded
 * again with GD, which writes no metadata: whatever the camera or phone left in them stays out.
 * Decoding also refuses anything that only claims to be an image.
 */
final readonly class DiskAnswerFileStore implements AnswerFileStoreInterface
{
    private const int IMAGE_QUALITY = 90;

    public function contentType(IncomingFile $file): ?string
    {
        if (! is_file($file->path)) {
            return null;
        }

        $type = (new finfo(FILEINFO_MIME_TYPE))->file($file->path);

        return is_string($type) ? $type : null;
    }

    public function store(SurveyId $surveyId, string $fileId, IncomingFile $file, string $contentType, bool $keepName): ?StoredAnswerFile
    {
        $kind = FileKind::forContentType($contentType);
        if (! $kind instanceof FileKind) {
            return null;
        }

        $contents = $kind === FileKind::Image ? $this->strippedImage($file->path, $contentType) : file_get_contents($file->path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $path = "surveys/{$surveyId->value}/{$fileId}.{$kind->contentTypes()[$contentType]}";
        $this->disk()->put($path, $contents);

        return new StoredAnswerFile(
            id: $fileId,
            surveyId: $surveyId->value,
            questionId: $file->questionId,
            path: $path,
            contentType: $contentType,
            size: strlen($contents),
            originalName: $keepName ? mb_substr(basename($file->clientName), 0, 255) : null,
        );
    }

    public function discard(array $files): void
    {
        $this->disk()->delete(array_map(static fn (StoredAnswerFile $file): string => $file->path, $files));
    }

    public function readStream(StoredAnswerFile $file): mixed
    {
        $stream = $this->disk()->readStream($file->path);

        return is_resource($stream) ? $stream : null;
    }

    public function deleteForSurvey(SurveyId $surveyId): void
    {
        $this->disk()->deleteDirectory("surveys/{$surveyId->value}");
    }

    public function pruneOrphans(DateTimeImmutable $before): int
    {
        $disk = $this->disk();
        $known = array_flip(SurveyAnswerFileModel::query()->pluck('path')->all());
        $pruned = 0;
        foreach ($disk->allFiles('surveys') as $path) {
            if (! isset($known[$path]) && $disk->lastModified($path) < $before->getTimestamp()) {
                $disk->delete($path);
                $pruned++;
            }
        }

        return $pruned;
    }

    private function strippedImage(string $path, string $contentType): ?string
    {
        try {
            $image = (new ImageManager(GdDriver::class, decodeAnimation: false, strip: true))->decodePath($path);

            return (string) $image->encode($this->encoder($contentType));
        } catch (Throwable) {
            return null;
        }
    }

    private function encoder(string $contentType): EncoderInterface
    {
        return match ($contentType) {
            'image/png' => new PngEncoder(strip: true),
            'image/webp' => new WebpEncoder(quality: self::IMAGE_QUALITY, strip: true),
            default => new JpegEncoder(quality: self::IMAGE_QUALITY, strip: true),
        };
    }

    private function disk(): Filesystem
    {
        $disk = config('modules.settings.surveys.files_disk', 'local');

        return Storage::disk(is_string($disk) && $disk !== '' ? $disk : 'local');
    }
}
