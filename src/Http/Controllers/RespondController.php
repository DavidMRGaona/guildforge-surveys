<?php

declare(strict_types=1);

namespace Modules\Surveys\Http\Controllers;

use App\Application\Seo\DTOs\SeoPageDTO;
use App\Application\Seo\Services\SeoMetadataFactoryInterface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\PublicLinkAccessService;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\InvalidSubmissionException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\Exceptions\SurveyNotAcceptingResponsesException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SubjectSnapshot;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Http\Requests\RequestPublicLinkRequest;
use Modules\Surveys\Http\Support\ResultsPayload;

/**
 * The page where a survey is answered: with an account, with a guest's link or with the
 * public link. Every outcome renders the same page with a state; a wrong link never fails
 * loudly. Answers are never logged. The verified public link asks for an address instead of
 * the questions, and always answers the same, whatever happened with it.
 */
final class RespondController extends Controller
{
    public const string RESULT_KEY = 'surveys.result';

    /** The survey the flashed result belongs to, so it never stands for another one. */
    public const string RESULT_SURVEY_KEY = 'surveys.result_survey';

    public function __construct(
        private readonly SurveyRepositoryInterface $surveys,
        private readonly SubmissionService $submissions,
        private readonly SeoMetadataFactoryInterface $seo,
        private readonly ResultsService $results,
        private readonly PublicLinkAccessService $access,
        private readonly SurveySubjectSourceRegistryInterface $sources,
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
        return $this->page($survey, Respondent::publicLink($token), route('surveys.public.store', [$survey, $token]), route('surveys.public.request', [$survey, $token]));
    }

    public function storePublic(Request $request, string $survey, string $token): RedirectResponse
    {
        return $this->submit($request, $survey, Respondent::publicLink($token), route('surveys.public', [$survey, $token]));
    }

    public function requestPublicLink(RequestPublicLinkRequest $request, string $survey, string $token): RedirectResponse
    {
        $this->access->requestLink(SurveyId::fromString($survey), $token, $request->email());

        return redirect()->route('surveys.public', [$survey, $token])->with(self::RESULT_KEY, 'link_sent')->with(self::RESULT_SURVEY_KEY, $survey);
    }

    private function page(string $surveyId, Respondent $respondent, string $submitUrl, ?string $requestUrl = null): Response
    {
        $id = SurveyId::fromString($surveyId);
        $state = $this->submissions->stateFor($id, $respondent);
        // An unknown survey looks like one the person may not answer: the page never tells
        // whether a survey exists, nor its title, to someone outside its audience
        if ($state === SubmissionService::STATE_NOT_FOUND) {
            $state = SubmissionService::STATE_NOT_INVITED;
        }
        $survey = $state === SubmissionService::STATE_NOT_INVITED ? null : $this->surveys->find($id);

        $props = [
            'state' => $state,
            'result' => session(self::RESULT_KEY),
            'seo' => $this->seo->forPage(new SeoPageDTO(
                title: $survey?->title() ?? (string) __('surveys::messages.response.title'),
                noindex: true,
            ))->toArray(),
        ];

        if ($survey instanceof Survey) {
            $props['survey'] = [
                'id' => $survey->id()->value,
                'kind' => $survey->kind()->value,
                'title' => $survey->title(),
                'description' => $survey->description(),
                'anonymous' => $survey->isAnonymous(),
                'closesAt' => $survey->closesAt()?->format(DATE_ATOM),
            ];
            $subject = $survey->subject();
            if ($subject instanceof SubjectSnapshot) {
                $props['subject'] = $this->subjectProps($subject);
            }
            // Whoever answers with an account can go back to what is still pending for them
            if ($respondent->kind === Respondent::ACCOUNT) {
                $props['profileUrl'] = route('profile.show', ['tab' => 'surveys']);
            }
        }

        if ($survey instanceof Survey && $state === SubmissionService::STATE_OPEN) {
            $props['definition'] = $survey->definition()->toArray();
            $props['submitUrl'] = $submitUrl;
        }

        if ($state === SubmissionService::STATE_VERIFY_EMAIL && $requestUrl !== null) {
            $props['requestUrl'] = $requestUrl;
        }

        // After answering (or once it has ended) a survey may share its results; with the public
        // link there is no participation, so the confirmation of the submission stands for it
        $hasAnswered = $state === SubmissionService::STATE_ANSWERED
            || ($state === SubmissionService::STATE_OPEN
                && $respondent->kind === Respondent::PUBLIC_LINK
                && session(self::RESULT_KEY) === 'submitted'
                && session(self::RESULT_SURVEY_KEY) === $id->value);
        if ($survey instanceof Survey && ($hasAnswered || $state === SubmissionService::STATE_CLOSED)) {
            $results = $this->results->forRespondents($id);
            if ($results->visible) {
                // Names of who voted are for the audience, never for whoever came through the public link
                $props['results'] = ResultsPayload::forRespondents($results, withVoters: ! $this->submissions->comesFromPublicLink($id, $respondent));
            }
        }

        return Inertia::render('Surveys/Respond', $props);
    }

    /**
     * What the survey is about, as it was when it opened. The kind of subject is named by its
     * source, or left out once the module that offered it is gone.
     *
     * @return array{typeLabel: ?string, title: string, startsAt: ?string, url: ?string}
     */
    private function subjectProps(SubjectSnapshot $subject): array
    {
        return [
            'typeLabel' => $this->sources->source($subject->sourceType)?->sourceLabel(),
            'title' => $subject->title,
            'startsAt' => $subject->startsAt?->format(DATE_ATOM),
            'url' => $subject->url,
        ];
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

        return redirect($pageUrl)->with(self::RESULT_KEY, 'submitted')->with(self::RESULT_SURVEY_KEY, $surveyId);
    }
}
