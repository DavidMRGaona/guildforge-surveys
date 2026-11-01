<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use App\Application\Services\TimezoneServiceInterface;
use App\Application\Surveys\Contracts\SurveySubjectSourceInterface;
use App\Application\Surveys\Services\SurveySubjectSourceRegistryInterface;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Surveys\Application\Contracts\PendingSurveysQueryInterface;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyParticipationModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyRecipientModel;

final readonly class EloquentPendingSurveysQuery implements PendingSurveysQueryInterface
{
    public function __construct(
        private TimezoneServiceInterface $timezone,
        private SurveySubjectSourceRegistryInterface $sources,
    ) {}

    public function forUser(string $userId, DateTimeImmutable $now): array
    {
        $surveys = SurveyModel::query()
            ->where('status', SurveyStatus::Open->value)
            ->where(fn (Builder $query): Builder => $query->whereNull('closes_at')->orWhere('closes_at', '>', $this->timezone->toStorage($now)))
            ->whereIn('id', SurveyRecipientModel::query()->where('user_id', $userId)->select('survey_id'))
            ->whereNotIn('id', SurveyParticipationModel::query()->where('user_id', $userId)->select('survey_id'))
            ->orderByRaw('closes_at is null')
            ->orderBy('closes_at')
            ->get();

        return array_values($surveys->map(fn (SurveyModel $survey): array => [
            'id' => $survey->id,
            'title' => $survey->title,
            'subjectType' => $this->subjectType($survey->subject_type),
            'subjectTitle' => is_string($survey->subject_snapshot['title'] ?? null) ? $survey->subject_snapshot['title'] : null,
            'closesAt' => $this->timezone->iso($survey->closes_at),
            'url' => route('surveys.respond', $survey->id),
        ])->all());
    }

    /**
     * The kind of thing the survey is about, as its module names it; null for general surveys
     * and for kinds whose module is gone.
     */
    private function subjectType(?string $type): ?string
    {
        $source = $type === null ? null : $this->sources->source($type);

        return $source instanceof SurveySubjectSourceInterface ? $source->sourceLabel() : null;
    }
}
