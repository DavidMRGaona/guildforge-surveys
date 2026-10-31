<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\Surveys\Application\Contracts\OrganizedSurveysQueryInterface;
use Modules\Surveys\Application\Services\SurveyManagerGate;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyOrganizerModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;

/**
 * @phpstan-import-type OrganizedSurvey from OrganizedSurveysQueryInterface
 */
final readonly class EloquentOrganizedSurveysQuery implements OrganizedSurveysQueryInterface
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyManagerGate $gate,
        private TimezoneServiceInterface $timezone,
    ) {}

    public function forUser(string $userId): array
    {
        return $this->list(
            SurveyModel::query()->whereIn('id', SurveyOrganizerModel::query()->where('user_id', $userId)->select('survey_id')),
            $userId,
        );
    }

    public function forSubject(string $sourceType, string $subjectId, string $userId): array
    {
        return $this->list(
            SurveyModel::query()->where('subject_type', $sourceType)->where('subject_id', $subjectId),
            $userId,
        );
    }

    /**
     * @param  Builder<SurveyModel>  $query
     * @return list<OrganizedSurvey>
     */
    private function list(Builder $query, string $userId): array
    {
        $ids = $query->whereNull('dismissed_at')->orderByDesc('created_at')->orderBy('id')->pluck('id')->all();
        $surveys = array_values(array_filter(
            $this->surveys->findMany(array_values(array_map(static fn (mixed $id): SurveyId => SurveyId::fromString((string) $id), $ids))),
            fn (Survey $survey): bool => $this->gate->canManage($userId, $survey),
        ));

        /** @var array<string, int> $responses */
        $responses = SurveyResponseModel::query()
            ->whereIn('survey_id', array_map(static fn (Survey $survey): string => $survey->id()->value, $surveys))
            ->groupBy('survey_id')
            ->selectRaw('survey_id, count(*) as total')
            ->pluck('total', 'survey_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();

        return array_map(fn (Survey $survey): array => $this->row($survey, $responses[$survey->id()->value] ?? 0), $surveys);
    }

    /**
     * @return OrganizedSurvey
     */
    private function row(Survey $survey, int $responses): array
    {
        $status = $this->status($survey);
        $offered = in_array($status, ['draft', 'to_send', 'scheduled', 'rejected'], true);
        $dismissable = $offered || $status === 'prepared';
        $id = $survey->id()->value;

        return [
            'id' => $id,
            'title' => $survey->title(),
            'subjectTitle' => $survey->subject()?->title,
            'status' => $status,
            'scheduledSendAt' => $this->timezone->iso($survey->scheduledSendAt()),
            'closesAt' => $this->timezone->iso($survey->closesAt()),
            'rejectionReason' => $survey->rejectionReason(),
            'responses' => $responses,
            'audienceSize' => $survey->audienceSize(),
            'sendUrl' => $offered ? route('surveys.manage.send', $id) : null,
            'sendOnEnd' => $survey->sendsOnEnd(),
            'sendDelayHours' => $survey->sendDelayHours(),
            'dismissUrl' => $dismissable && ! $survey->wasOpened() ? route('surveys.manage.dismiss', $id) : null,
            'editUrl' => $status === 'closed' ? null : route('surveys.manage.edit', $id),
            'resultsUrl' => $survey->wasOpened() ? route('surveys.manage.results', $id) : null,
            'closeUrl' => $status === 'open' ? route('surveys.manage.close', $id) : null,
        ];
    }

    /**
     * A subject's end survey offered to its organizers is "to_send"; any other survey not sent
     * yet (one the organizer made themselves, for example) is a "draft".
     *
     * @return 'draft'|'prepared'|'to_send'|'scheduled'|'pending_review'|'rejected'|'open'|'closed'
     */
    private function status(Survey $survey): string
    {
        return match (true) {
            $survey->status() === SurveyStatus::Open => 'open',
            $survey->status() === SurveyStatus::Closed => 'closed',
            $survey->status() === SurveyStatus::PendingReview => 'pending_review',
            $survey->isAwaitingSubjectEnd() => 'prepared',
            $survey->rejectionReason() !== null => 'rejected',
            $survey->scheduledSendAt() instanceof \DateTimeImmutable => 'scheduled',
            $survey->subjectEndKey() === null => 'draft',
            default => 'to_send',
        };
    }
}
