<?php

declare(strict_types=1);

namespace Modules\Surveys\Tests\Support;

use Illuminate\Http\UploadedFile;
use Modules\Surveys\Application\DTOs\IncomingFile;

/**
 * Files people could send: a photo carrying EXIF data, a small PDF and a file that only claims
 * to be an image.
 */
final class ExampleFiles
{
    /** Text hidden in the photo's EXIF block, which must never reach the stored file. */
    public const string EXIF_SECRET = 'GPS-40.4168-3.7038';

    public static function photo(string $name = 'ejercito.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        // An APP1 segment right after the start marker: "Exif", an empty big-endian TIFF directory
        // and the secret, as cameras and phones leave location and owner there
        $payload = "Exif\x00\x00MM\x00\x2a\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00".self::EXIF_SECRET;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return self::upload(substr($jpeg, 0, 2).$segment.substr($jpeg, 2), $name, 'image/jpeg');
    }

    public static function pdf(string $name = 'lista.pdf'): UploadedFile
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        return self::upload($pdf, $name, 'application/pdf');
    }

    /**
     * A small PNG whose header claims 20000 × 20000 pixels: decoding it would take gigabytes.
     */
    public static function hugeImage(string $name = 'bomba.png'): UploadedFile
    {
        $ihdr = 'IHDR'.pack('NN', 20000, 20000)."\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));

        return self::upload($png, $name, 'image/png');
    }

    /** Text with an image's name and type: only its contents tell what it is. */
    public static function fakeImage(string $name = 'foto.jpg'): UploadedFile
    {
        return self::upload("<?php echo 'hola';\n", $name, 'image/jpeg');
    }

    public static function incoming(string $questionId, UploadedFile $file, ?int $size = null): IncomingFile
    {
        return new IncomingFile($questionId, (string) $file->getRealPath(), $file->getClientOriginalName(), $size ?? (int) $file->getSize());
    }

    private static function upload(string $contents, string $name, string $type): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'survey-file');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, $type, null, true);
    }
}
