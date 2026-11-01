<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\TrendsQueryInterface;
use Modules\Surveys\Domain\Enums\SurveyStatus;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyOrganizerModel;
use stdClass;

/**
 * The same GROUP BY queries as a single survey's results, grouped by survey too, and run in
 * batches so a long history never reaches the database's limit on bound parameters.
 */
final readonly class EloquentTrendsQuery implements TrendsQueryInterface
{
    public function __construct(private int $batchSize = 500) {}

    public function responseCounts(array $surveyIds): array
    {
        return $this->countResponses($surveyIds, verifiedOnly: false);
    }

    public function verifiedResponseCounts(array $surveyIds): array
    {
        return $this->countResponses($surveyIds, verifiedOnly: true);
    }

    public function answeredCounts(array $surveyIds, array $questionIds): array
    {
        $counts = [];
        foreach ([false, true] as $verifiedOnly) {
            $this->each($surveyIds, $questionIds, static fn (Builder $query): Builder => ($verifiedOnly ? $query->where('r.verified', true) : $query)
                ->selectRaw('r.survey_id, a.question_id, count(distinct a.response_id) as total')
                ->groupBy('r.survey_id', 'a.question_id'), function (stdClass $row) use (&$counts, $verifiedOnly): void {
                    $surveyId = $this->string($row->survey_id);
                    $questionId = $this->string($row->question_id);
                    $counts[$surveyId][$questionId] ??= ['all' => 0, 'verified' => 0];
                    $counts[$surveyId][$questionId][$verifiedOnly ? 'verified' : 'all'] = $this->int($row->total);
                });
        }

        return $counts;
    }

    public function optionCounts(array $surveyIds, array $questionIds): array
    {
        $counts = [];
        $this->each($surveyIds, $questionIds, static fn (Builder $query): Builder => $query
            ->whereNull('a.row_id')
            ->where(static fn (Builder $inner) => $inner->whereNotNull('a.option_id')->orWhereNotNull('a.text_value'))
            ->selectRaw('r.survey_id, a.question_id, a.option_id, count(*) as total')
            ->groupBy('r.survey_id', 'a.question_id', 'a.option_id'), function (stdClass $row) use (&$counts): void {
                $option = $row->option_id === null ? Submission::OTHER : $this->string($row->option_id);
                $counts[$this->string($row->survey_id)][$this->string($row->question_id)][$option] = $this->int($row->total);
            });

        return $counts;
    }

    public function numberCounts(array $surveyIds, array $questionIds): array
    {
        $counts = [];
        $this->each($surveyIds, $questionIds, static fn (Builder $query): Builder => $query
            ->whereNotNull('a.number_value')
            ->selectRaw('r.survey_id, a.question_id, a.number_value, count(*) as total')
            ->groupBy('r.survey_id', 'a.question_id', 'a.number_value'), function (stdClass $row) use (&$counts): void {
                $counts[$this->string($row->survey_id)][$this->string($row->question_id)][$this->int($row->number_value)] = $this->int($row->total);
            });

        return $counts;
    }

    public function cellCounts(array $surveyIds, array $questionIds): array
    {
        $counts = [];
        $this->each($surveyIds, $questionIds, static fn (Builder $query): Builder => $query
            ->whereNotNull('a.row_id')
            ->whereNotNull('a.option_id')
            ->selectRaw('r.survey_id, a.question_id, a.row_id, a.option_id, count(*) as total')
            ->groupBy('r.survey_id', 'a.question_id', 'a.row_id', 'a.option_id'), function (stdClass $row) use (&$counts): void {
                $counts[$this->string($row->survey_id)][$this->string($row->question_id)][$this->string($row->row_id)][$this->string($row->option_id)] = $this->int($row->total);
            });

        return $counts;
    }

    public function positionCounts(array $surveyIds, array $questionIds): array
    {
        $counts = [];
        $this->each($surveyIds, $questionIds, static fn (Builder $query): Builder => $query
            ->whereNotNull('a.option_id')
            ->whereNotNull('a.number_value')
            ->selectRaw('r.survey_id, a.question_id, a.option_id, a.number_value, count(*) as total')
            ->groupBy('r.survey_id', 'a.question_id', 'a.option_id', 'a.number_value'), function (stdClass $row) use (&$counts): void {
                $counts[$this->string($row->survey_id)][$this->string($row->question_id)][$this->string($row->option_id)][$this->int($row->number_value)] = $this->int($row->total);
            });

        return $counts;
    }

    public function userNames(array $userIds): array
    {
        $names = [];
        foreach ($this->batches($userIds) as $batch) {
            foreach (DB::table('users')->whereIn('id', $batch)->get(['id', 'name']) as $row) {
                $names[$this->string($row->id)] = $this->string($row->name);
            }
        }

        return $names;
    }

    public function templateIds(?string $organizerId = null): array
    {
        $query = SurveyModel::query()
            ->whereNotNull('template_id')
            ->whereNotNull('opens_at')
            ->whereIn('status', [SurveyStatus::Open->value, SurveyStatus::Closed->value]);

        if ($organizerId !== null) {
            $query->whereIn('id', SurveyOrganizerModel::query()->where('user_id', $organizerId)->select('survey_id'));
        }

        return array_values(array_map($this->string(...), $query->distinct()->pluck('template_id')->all()));
    }

    /**
     * @param  list<string>  $surveyIds
     * @return array<string, int>
     */
    private function countResponses(array $surveyIds, bool $verifiedOnly): array
    {
        $counts = [];
        foreach ($this->batches($surveyIds) as $batch) {
            $query = DB::table('surveys_responses')->whereIn('survey_id', $batch);
            if ($verifiedOnly) {
                $query->where('verified', true);
            }

            foreach ($query->groupBy('survey_id')->selectRaw('survey_id, count(*) as total')->get() as $row) {
                $counts[$this->string($row->survey_id)] = $this->int($row->total);
            }
        }

        return $counts;
    }

    /**
     * Runs the query for every batch of surveys and hands each row over.
     *
     * @param  list<string>  $surveyIds
     * @param  list<string>  $questionIds
     * @param  callable(Builder): Builder  $shape
     * @param  callable(stdClass): void  $collect
     */
    private function each(array $surveyIds, array $questionIds, callable $shape, callable $collect): void
    {
        if ($questionIds === []) {
            return;
        }

        foreach ($this->batches($surveyIds) as $batch) {
            $query = DB::table('surveys_answers as a')
                ->join('surveys_responses as r', 'r.id', '=', 'a.response_id')
                ->whereIn('r.survey_id', $batch)
                ->whereIn('a.question_id', $questionIds);

            foreach ($shape($query)->get() as $row) {
                $collect($row);
            }
        }
    }

    /**
     * @param  list<string>  $ids
     * @return list<list<string>>
     */
    private function batches(array $ids): array
    {
        return array_chunk(array_values(array_unique($ids)), max(1, $this->batchSize));
    }

    private function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
