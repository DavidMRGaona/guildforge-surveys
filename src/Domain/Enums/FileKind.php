<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\Enums;

/**
 * What a file question may take. Each kind lists the content types it stands for, read from
 * the file itself, never from its name.
 */
enum FileKind: string
{
    case Image = 'image';
    case Pdf = 'pdf';

    /**
     * Content type => the extension a stored file of it gets.
     *
     * @return array<string, string>
     */
    public function contentTypes(): array
    {
        return match ($this) {
            self::Image => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
            self::Pdf => ['application/pdf' => 'pdf'],
        };
    }

    public static function forContentType(string $contentType): ?self
    {
        foreach (self::cases() as $kind) {
            if (array_key_exists($contentType, $kind->contentTypes())) {
                return $kind;
            }
        }

        return null;
    }
}
