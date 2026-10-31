<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Services;

use App\Application\Surveys\Contracts\SurveySubjectLinksInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\DTOs\SurveyLinkDTO;
use App\Application\Surveys\DTOs\SurveySubjectDTO;
use App\Application\Surveys\DTOs\SurveySubjectLinksDTO;
use App\Application\Surveys\Enums\SurveyLinkAction;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use Modules\Surveys\Application\DTOs\Respondent;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Application\Services\SubmissionService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;

/**
 * The links a subject's page (in another module) shows one person: the subject's surveys page
 * when they manage it, asked live to its source, and the surveys sent to them, to answer while
 * open or to see the results once the survey shares them, by the same rules as the answer page.
 */
final readonly class SurveySubjectLinks implements SurveySubjectLinksInterface
{
    public function __construct(
        private SurveySubjectSourceRegistryInterface $sources,
        private SurveyRepositoryInterface $surveys,
        private SubmissionService $submissions,
        private ResultsService $results,
    ) {}

    public function forSubject(string $sourceType, string $subjectId, ?string $userId): SurveySubjectLinksDTO
    {
        $source = $this->sources->source($sourceType);
        if ($userId === null || ! $source instanceof SurveySubjectSourceInterface || ! $source->find($subjectId) instanceof SurveySubjectDTO) {
            return SurveySubjectLinksDTO::none();
        }

        $links = [];
        foreach ($this->surveys->findBySubject($sourceType, $subjectId) as $survey) {
            $link = $survey->wasOpened() ? $this->linkFor($survey, $userId) : null;
            if ($link instanceof SurveyLinkDTO) {
                $links[] = $link;
            }
        }

        return new SurveySubjectLinksDTO(
            manageUrl: $source->canManage($userId, $subjectId) ? route('surveys.subject', [$sourceType, $subjectId]) : null,
            surveys: $links,
        );
    }

    private function linkFor(Survey $survey, string $userId): ?SurveyLinkDTO
    {
        $id = $survey->id();
        $url = route('surveys.respond', $id->value);

        return match ($this->submissions->stateFor($id, Respondent::account($userId))) {
            SubmissionService::STATE_OPEN => new SurveyLinkDTO($survey->title(), $url, SurveyLinkAction::Respond),
            SubmissionService::STATE_ANSWERED, SubmissionService::STATE_CLOSED => $this->results->forRespondents($id)->visible
                ? new SurveyLinkDTO($survey->title(), $url, SurveyLinkAction::ViewResults)
                : null,
            default => null,
        };
    }
}
