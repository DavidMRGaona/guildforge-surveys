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
use Modules\Surveys\Application\DTOs\Results\GridResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\ResultBucketDTO;
use Modules\Surveys\Application\DTOs\Results\SurveyResultsDTO;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Exceptions\AlreadyAnsweredException;
use Modules\Surveys\Domain\Exceptions\InvalidSubmissionException;
use Modules\Surveys\Domain\Exceptions\NotInvitedException;
use Modules\Surveys\Domain\Exceptions\SurveyNotAcceptingResponsesException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
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
        private readonly ResultsService $results,
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
        }

        if ($survey instanceof Survey && $state === SubmissionService::STATE_OPEN) {
            $props['definition'] = $survey->definition()->toArray();
            $props['submitUrl'] = $submitUrl;
        }

        // After answering (or once it has ended) a survey may share its results; with the public
        // link there is no participation, so the confirmation of the submission stands for it
        $hasAnswered = $state === SubmissionService::STATE_ANSWERED
            || ($state === SubmissionService::STATE_OPEN && $respondent->kind === Respondent::PUBLIC_LINK && session(self::RESULT_KEY) === 'submitted');
        if ($survey instanceof Survey && ($hasAnswered || $state === SubmissionService::STATE_CLOSED)) {
            $results = $this->results->forRespondents($id);
            if ($results->visible) {
                $props['results'] = $this->resultsPayload($results);
            }
        }

        return Inertia::render('Surveys/Respond', $props);
    }

    /**
     * @return array{responses: int, questions: list<array<string, mixed>>}
     */
    private function resultsPayload(SurveyResultsDTO $results): array
    {
        return [
            'responses' => $results->responses,
            'questions' => array_map(static fn (QuestionResultDTO $question): array => [
                'id' => $question->questionId,
                'title' => $question->title,
                'type' => $question->type->value,
                'answered' => $question->answered,
                'belowMinimum' => $question->belowMinimum,
                'buckets' => array_map(static fn (ResultBucketDTO $bucket): array => [
                    'key' => $bucket->key,
                    'label' => $bucket->label,
                    'count' => $bucket->count,
                    'voters' => $bucket->voters,
                ], $question->buckets),
                'grid' => $question->grid instanceof GridResultDTO ? [
                    'rows' => array_map(static fn (Option $row): array => $row->toArray(), $question->grid->rows),
                    'columns' => array_map(static fn (Option $column): array => $column->toArray(), $question->grid->columns),
                    'counts' => $question->grid->counts,
                ] : null,
                'mean' => $question->mean,
                'median' => $question->median,
            ], $results->questions),
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

        return redirect($pageUrl)->with(self::RESULT_KEY, 'submitted');
    }
}
