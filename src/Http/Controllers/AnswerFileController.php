<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Surveys\Application\Contracts\AnswerFileStoreInterface;
use Modules\Surveys\Application\Services\AnswerFileAccess;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Enums\FileKind;
use Modules\Surveys\Infrastructure\Files\AnswerFileLinks;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands out a file sent as an answer. The link is signed and short-lived (AnswerFileLinks), and
 * the signature is not enough: whoever opens it must still see the survey's results, in the panel
 * or as the subject's organizer, and the results must show the file now. Only images open in the
 * browser; anything else is downloaded, and nothing the file says about itself is trusted.
 */
final class AnswerFileController extends Controller
{
    public function __construct(
        private readonly AnswerFileAccess $access,
        private readonly AnswerFileStoreInterface $files,
        private readonly SurveyManagerGate $gate,
    ) {}

    public function show(Request $request, string $file): StreamedResponse
    {
        $found = $this->access->visible($file);
        abort_unless($found !== null, 404);
        [$stored, $survey] = $found;

        $user = $request->user();
        $userId = (string) $user?->getAuthIdentifier();
        $model = SurveyModel::query()->find($survey->id()->value);
        abort_unless($user?->can('viewResults', $model) === true || $this->gate->canManage($userId, $survey), 403);

        $stream = $this->files->readStream($stored);
        abort_unless($stream !== null, 404);

        $inline = $request->boolean('inline') && FileKind::forContentType($stored->contentType) === FileKind::Image;
        $name = AnswerFileLinks::downloadName($stored);

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $stored->contentType,
            'Content-Length' => (string) $stored->size,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                AnswerFileLinks::asciiName($name),
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
