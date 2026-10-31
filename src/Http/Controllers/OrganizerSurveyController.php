<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Surveys\Application\Contracts\OrganizedSurveysQueryInterface;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * A subject's surveys for whoever organizes it, without the panel: its page (module pages link
 * to it), sending an offered survey with one click and choosing not to send it. Who may is
 * asked to the subject's source on every request.
 */
final class OrganizerSurveyController extends Controller
{
    public function __construct(
        private readonly SurveySubjectSourceRegistryInterface $sources,
        private readonly OrganizedSurveysQueryInterface $organized,
        private readonly OrganizerSurveyService $service,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function subject(Request $request, string $type, string $subject): Response
    {
        $source = $this->sources->source($type);
        $found = $source?->find($subject);
        abort_unless($found instanceof SurveySubjectDTO, 404);

        $userId = (string) $request->user()?->getAuthIdentifier();
        abort_unless($source->canManage($userId, $subject), 403);

        return Inertia::render('Surveys/Manage/Subject', [
            'subject' => [
                'title' => $found->title,
                'typeLabel' => $source->sourceLabel(),
                'url' => $found->url,
            ],
            'surveys' => $this->organized->forSubject($type, $subject, $userId),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('surveys::messages.manage.subject_title', ['title' => $found->title]),
                noindex: true,
            ))->toArray(),
        ]);
    }

    public function send(Request $request, string $survey): RedirectResponse
    {
        $outcome = $this->act(fn (): SendOutcome => $this->service->send(SurveyId::fromString($survey), (string) $request->user()?->getAuthIdentifier()));
        if ($outcome === null) {
            return back()->with('error', __('surveys::messages.manage.not_sendable'));
        }

        return back()->with('success', __($outcome === SendOutcome::Opened ? 'surveys::messages.manage.sent' : 'surveys::messages.manage.sent_for_review'));
    }

    public function dismiss(Request $request, string $survey): RedirectResponse
    {
        $done = $this->act(function () use ($request, $survey): bool {
            $this->service->dismiss(SurveyId::fromString($survey), (string) $request->user()?->getAuthIdentifier());

            return true;
        });

        return $done === null
            ? back()->with('error', __('surveys::messages.manage.not_dismissable'))
            : back()->with('success', __('surveys::messages.manage.dismissed'));
    }

    /**
     * Runs an organizer's action: an unknown survey is a 404 and a stranger a 403; a survey no
     * longer in a state for it (sent meanwhile, for example) gives null.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T|null
     */
    private function act(callable $action): mixed
    {
        try {
            return $action();
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        } catch (InvalidSurveyStateException) {
            return null;
        }
    }
}
