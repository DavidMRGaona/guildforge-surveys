<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Files;

use DateTimeInterface;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Surveys\Application\DTOs\StoredAnswerFile;

/**
 * Signed links to the files sent as answers. A link only opens the file for someone who may see
 * the results (AnswerFileController): a forwarded one gives nothing away, and it expires.
 */
final class AnswerFileLinks
{
    /** Pages showing results get links for the time someone reads them. */
    public const int PAGE_MINUTES = 30;

    /** An export is read later, away from the page. */
    public const int EXPORT_DAYS = 7;

    public static function forPage(StoredAnswerFile $file, bool $inline = false): string
    {
        return self::signed($file, now()->addMinutes(self::PAGE_MINUTES), $inline);
    }

    public static function forExport(StoredAnswerFile $file): string
    {
        return self::signed($file, now()->addDays(self::EXPORT_DAYS), false);
    }

    /**
     * The name it came with on a named survey; on an anonymous one, a neutral name.
     */
    public static function downloadName(StoredAnswerFile $file): string
    {
        if ($file->originalName !== null && trim($file->originalName) !== '') {
            return $file->originalName;
        }

        $extension = pathinfo($file->path, PATHINFO_EXTENSION);
        $name = (string) __('surveys::messages.files.download_name', ['id' => substr($file->id, 0, 8)]);

        return $extension === '' ? $name : "{$name}.{$extension}";
    }

    public static function asciiName(string $name): string
    {
        $ascii = Str::ascii($name);
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii) ?? '';

        return trim($ascii, '-') === '' ? 'archivo' : $ascii;
    }

    private static function signed(StoredAnswerFile $file, DateTimeInterface $expires, bool $inline): string
    {
        return URL::temporarySignedRoute('surveys.files.show', $expires, ['file' => $file->id, ...($inline ? ['inline' => 1] : [])]);
    }
}
