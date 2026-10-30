<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\InvalidSubmissionException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\Exceptions\SurveyNotAcceptingResponsesException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * The page where a survey is answered: with an account, with a guest's link or with the
 * public link. Every outcome renders the same page with a state; a wrong link never fails
 * loudly. Answers are never logged.
 */
final class RespondController extends Controller
{
    public const string RESULT_KEY = 'surveys.result';

    public function __construct(
        private readonly SurveyRepositoryInterface $surveys,
        private readonly SubmissionService $submissions,
        private readonly SeoMetadataFactoryInterface $seo,
    ) {}

    public function show(Request $request, string $survey): Response
    {
        return $this->page($survey, Respondent::account((string) $request->user()?->getAuthIdentifier()), route('surveys.respond.store', $survey));
    }

    public function store(Request $request, string $survey): RedirectResponse
    {
        return $this->submit($request, $survey, Respondent::account((string) $request->user()?->getAuthIdentifier()), route('surveys.respond', $survey));
    }

    public function showGuest(string $survey, string $token): Response
    {
        return $this->page($survey, Respondent::guest($token), route('surveys.guest.store', [$survey, $token]));
    }

    public function storeGuest(Request $request, string $survey, string $token): RedirectResponse
    {
        return $this->submit($request, $survey, Respondent::guest($token), route('surveys.guest', [$survey, $token]));
    }

    public function showPublic(string $survey, string $token): Response
    {
        return $this->page($survey, Respondent::publicLink($token), route('surveys.public.store', [$survey, $token]));
    }

    public function storePublic(Request $request, string $survey, string $token): RedirectResponse
    {
        return $this->submit($request, $survey, Respondent::publicLink($token), route('surveys.public', [$survey, $token]));
    }

    private function page(string $surveyId, Respondent $respondent, string $submitUrl): Response
    {
        $id = SurveyId::fromString($surveyId);
        $state = $this->submissions->stateFor($id, $respondent);
        $survey = $state === SubmissionService::STATE_NOT_FOUND ? null : $this->surveys->find($id);

        $props = [
            'state' => $state,
            'result' => session(self::RESULT_KEY),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: $survey?->title() ?? (string) __('surveys::messages.response.title'),
                noindex: true,
            ))->toArray(),
        ];

        if ($survey instanceof Survey && $state !== SubmissionService::STATE_NOT_INVITED) {
            $props['survey'] = [
                'id' => $survey->id()->value,
                'kind' => $survey->kind()->value,
                'title' => $survey->title(),
                'description' => $survey->description(),
                'anonymous' => $survey->isAnonymous(),
                'closesAt' => $survey->closesAt()?->format(DATE_ATOM),
            ];
        }

        if ($survey instanceof Survey && $state === SubmissionService::STATE_OPEN) {
            $props['definition'] = $survey->definition()->toArray();
            $props['submitUrl'] = $submitUrl;
        }

        return Inertia::render('Surveys/Respond', $props);
    }

    private function submit(Request $request, string $surveyId, Respondent $respondent, string $pageUrl): RedirectResponse
    {
        $answers = $request->input('answers', []);

        try {
            $this->submissions->submit(SurveyId::fromString($surveyId), $respondent, is_array($answers) ? $answers : []);
        } catch (InvalidSubmissionException $exception) {
            $errors = [];
            foreach ($exception->errors as $questionId => $key) {
                $errors["answers.{$questionId}"] = __("surveys::messages.submission_errors.{$key}");
            }

            return back()->withErrors($errors)->withInput();
        } catch (AlreadyAnsweredException) {
            return back()->withErrors(['survey' => __('surveys::messages.response.already_answered')]);
        } catch (SurveyNotAcceptingResponsesException) {
            return back()->withErrors(['survey' => __('surveys::messages.response.closed')]);
        } catch (NotInvitedException) {
            return back()->withErrors(['survey' => __('surveys::messages.response.not_invited')]);
        }

        return redirect($pageUrl)->with(self::RESULT_KEY, 'submitted');
    }
}
