<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Surveys\Contracts\SubjectEndSurveyChoiceInterface;
use App\Application\Surveys\DTOs\EndSurveyChoiceDTO;
use App\Application\Surveys\DTOs\EndSurveyOptionDTO;
use App\Application\Surveys\DTOs\EndSurveySelection;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\Enums\EndSurveyChoiceOutcome;
use App\Application\Surveys\Enums\SurveySubjectStatus;
use App\Application\Surveys\Enums\SurveySurface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Surveys\Application\Contracts\OrganizedSurveysQueryInterface;
use Modules\Surveys\Application\Enums\SendOutcome;
use Modules\Surveys\Application\Exceptions\SurveyPreparationUnavailableException;
use Modules\Surveys\Application\Services\OrganizerSurveyService;
use Modules\Surveys\Domain\Exceptions\InvalidSurveyStateException;
use Modules\Surveys\Domain\Exceptions\SubjectNotFoundException;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * A subject's surveys for whoever organizes it, without the panel: its page (module pages link
 * to it), choosing and preparing its end survey ahead, sending a survey with one click,
 * choosing not to send it and closing an open one. Who may is asked to the subject's source on
 * every request.
 */
final class OrganizerSurveyController extends Controller
{
    public function __construct(
        private readonly SurveySubjectSourceRegistryInterface $sources,
        private readonly OrganizedSurveysQueryInterface $organized,
        private readonly OrganizerSurveyService $service,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly SubjectEndSurveyChoiceInterface $endChoice,
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
                'status' => $found->status->value,
            ],
            'surveys' => $this->organized->forSubject($type, $subject, $userId),
            'prepareUrl' => $this->service->preparationAvailable($found) ? route('surveys.manage.prepare', [$type, $subject]) : null,
            // A cancelled subject has nobody left to ask
            'endChoice' => $this->endChoiceProps($type, $subject, $userId),
            'createUrls' => $found->status === SurveySubjectStatus::Cancelled ? null : [
                'form' => route('surveys.manage.create', [$type, $subject, 'encuesta']),
                'poll' => route('surveys.manage.create', [$type, $subject, 'sondeo']),
                'availability' => route('surveys.manage.create', [$type, $subject, 'disponibilidad']),
            ],
            'copy' => $found->status === SurveySubjectStatus::Cancelled ? null : $this->copyProps($type, $subject, $userId),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: (string) __('surveys::messages.manage.subject_title', ['title' => $found->title]),
                noindex: true,
            ))->toArray(),
        ]);
    }

    /**
     * Prepares the subject's end survey from its type's template and opens it in the editor.
     */
    public function prepare(Request $request, string $type, string $subject): RedirectResponse
    {
        try {
            $survey = $this->service->prepare($type, $subject, (string) $request->user()?->getAuthIdentifier());
        } catch (SubjectNotFoundException) {
            abort(404);
        } catch (AuthorizationException) {
            abort(403);
        } catch (SurveyPreparationUnavailableException $exception) {
            return back()->with('error', __("surveys::messages.manage.prepare_unavailable.{$exception->reason}"));
        }

        return redirect()->route('surveys.manage.edit', $survey->id()->value)
            ->with('success', __('surveys::messages.manage.prepared'));
    }

    /**
     * Saves which survey goes out when the subject ends, from the options its page offered. A
     * survey of its own opens in the editor right away.
     */
    public function chooseEnd(Request $request, string $type, string $subject): RedirectResponse
    {
        $source = $this->sources->source($type);
        abort_unless($source?->find($subject) instanceof SurveySubjectDTO, 404);

        $userId = (string) $request->user()?->getAuthIdentifier();
        $offer = $this->endChoice->forForm($type, $subject, $userId, SurveySurface::Web);
        abort_unless($offer instanceof EndSurveyChoiceDTO, 403);

        $values = static fn (array $options): array => array_map(static fn (EndSurveyOptionDTO $option): string => $option->value, $options);
        $data = $request->validate([
            'choice' => ['required', 'string', Rule::in($values($offer->options))],
            'base' => ['nullable', 'string', Rule::requiredIf($request->input('choice') === $offer->ownValue), Rule::in($values($offer->baseTemplates))],
        ]);

        try {
            $outcome = $this->endChoice->choose($type, $subject, new EndSurveySelection($data['choice'], $data['choice'] === $offer->ownValue ? $data['base'] : null), $userId);
        } catch (AuthorizationException) {
            abort(403);
        }

        if ($outcome === EndSurveyChoiceOutcome::Locked) {
            return back()->with('error', $this->endChoice->forForm($type, $subject, $userId, SurveySurface::Web)->lockedReason ?? $offer->lockedReason);
        }

        $editUrl = $data['choice'] === $offer->ownValue ? $this->endChoice->forForm($type, $subject, $userId, SurveySurface::Web)?->editUrl : null;
        $message = __($outcome === EndSurveyChoiceOutcome::ReleasedAsDraft ? 'surveys::messages.manage.end_choice_released' : 'surveys::messages.manage.end_choice_saved');

        return $editUrl !== null ? redirect()->to($editUrl)->with('success', $message) : back()->with('success', $message);
    }

    /**
     * The surveys the organizer may start this subject's from: those of every subject they
     * organized and still manage, named with their subject.
     *
     * @return array{url: string, surveys: list<array{value: string, label: string}>}|null
     */
    private function copyProps(string $type, string $subject, string $userId): ?array
    {
        $surveys = array_map(
            static fn (array $survey): array => [
                'value' => $survey['id'],
                'label' => $survey['subjectTitle'] === null ? $survey['title'] : "{$survey['title']} · {$survey['subjectTitle']}",
            ],
            $this->organized->forUser($userId),
        );

        return $surveys === [] ? null : ['url' => route('surveys.manage.copy', [$type, $subject]), 'surveys' => $surveys];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function endChoiceProps(string $type, string $subject, string $userId): ?array
    {
        $choice = $this->endChoice->forForm($type, $subject, $userId, SurveySurface::Web);
        if (! $choice instanceof EndSurveyChoiceDTO) {
            return null;
        }

        $option = static fn (EndSurveyOptionDTO $option): array => ['value' => $option->value, 'label' => $option->label];

        return [
            'options' => array_map($option, $choice->options),
            'selected' => $choice->selected,
            'ownValue' => $choice->ownValue,
            'baseTemplates' => array_map($option, $choice->baseTemplates),
            'locked' => $choice->locked,
            'lockedReason' => $choice->lockedReason,
            'editUrl' => $choice->editUrl,
            'url' => route('surveys.manage.end-choice', [$type, $subject]),
        ];
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
     * Closes an open survey before its date.
     */
    public function close(Request $request, string $survey): RedirectResponse
    {
        $done = $this->act(function () use ($request, $survey): bool {
            $this->service->close(SurveyId::fromString($survey), (string) $request->user()?->getAuthIdentifier());

            return true;
        });

        return $done === null
            ? back()->with('error', __('surveys::messages.manage.not_closable'))
            : back()->with('success', __('surveys::messages.manage.closed'));
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
