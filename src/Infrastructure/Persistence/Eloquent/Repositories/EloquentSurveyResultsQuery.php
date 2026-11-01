<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Persistence\Eloquent\Repositories;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Surveys\Application\Contracts\SurveyResultsQueryInterface;
use Modules\Surveys\Domain\ValueObjects\Submission\AnswerValue;
use Modules\Surveys\Domain\ValueObjects\Submission\Submission;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyResponseModel;

/**
 * Plain GROUP BY queries over surveys_answers, the same on PostgreSQL and SQLite.
 */
final class EloquentSurveyResultsQuery implements SurveyResultsQueryInterface
{
    public function responseCount(SurveyId $surveyId): int
    {
        return SurveyResponseModel::query()->where('survey_id', $surveyId->value)->count();
    }

    public function verifiedResponseCount(SurveyId $surveyId): int
    {
        return SurveyResponseModel::query()->where('survey_id', $surveyId->value)->where('verified', true)->count();
    }

    public function answeredCounts(SurveyId $surveyId, array $questionIds): array
    {
        return $this->answeredBy($this->answers($surveyId, $questionIds));
    }

    public function verifiedAnsweredCounts(SurveyId $surveyId, array $questionIds): array
    {
        return $this->answeredBy($this->answers($surveyId, $questionIds)->where('r.verified', true));
    }

    public function optionCounts(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->whereNull('a.row_id')
            ->where(static fn (Builder $query) => $query->whereNotNull('a.option_id')->orWhereNotNull('a.text_value'))
            ->selectRaw('a.question_id, a.option_id, count(*) as total')
            ->groupBy('a.question_id', 'a.option_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->string($row->question_id)][$row->option_id === null ? Submission::OTHER : $this->string($row->option_id)] = $this->int($row->total);
        }

        return $counts;
    }

    public function cellCounts(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->whereNotNull('a.row_id')
            ->whereNotNull('a.option_id')
            ->selectRaw('a.question_id, a.row_id, a.option_id, count(*) as total')
            ->groupBy('a.question_id', 'a.row_id', 'a.option_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->string($row->question_id)][$this->string($row->row_id)][$this->string($row->option_id)] = $this->int($row->total);
        }

        return $counts;
    }

    public function positionCounts(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->whereNotNull('a.option_id')
            ->whereNotNull('a.number_value')
            ->selectRaw('a.question_id, a.option_id, a.number_value, count(*) as total')
            ->groupBy('a.question_id', 'a.option_id', 'a.number_value')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->string($row->question_id)][$this->string($row->option_id)][$this->int($row->number_value)] = $this->int($row->total);
        }

        return $counts;
    }

    public function numberCounts(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->whereNotNull('a.number_value')
            ->selectRaw('a.question_id, a.number_value, count(*) as total')
            ->groupBy('a.question_id', 'a.number_value')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->string($row->question_id)][$this->int($row->number_value)] = $this->int($row->total);
        }

        return $counts;
    }

    public function textCounts(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->whereNotNull('a.text_value')
            ->selectRaw('a.question_id, a.text_value, count(*) as total')
            ->groupBy('a.question_id', 'a.text_value')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$this->string($row->question_id)][$this->string($row->text_value)] = $this->int($row->total);
        }

        return $counts;
    }

    public function texts(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->whereNull('a.option_id')
            ->whereNull('a.row_id')
            ->whereNotNull('a.text_value')
            ->where('a.text_value', '!=', '')
            ->select(['a.question_id', 'a.text_value'])
            ->orderBy('a.id')
            ->get();

        $texts = [];
        foreach ($rows as $row) {
            $texts[$this->string($row->question_id)][] = $this->string($row->text_value);
        }

        return $texts;
    }

    public function voters(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->whereNotNull('a.option_id')
            ->whereNull('a.row_id')
            ->select(['a.question_id', 'a.option_id', 'u.name as user_name', 'r.guest_name'])
            ->get();

        $voters = [];
        foreach ($rows as $row) {
            $name = $row->user_name ?? $row->guest_name;
            if (is_string($name) && $name !== '') {
                $voters[$this->string($row->question_id)][$this->string($row->option_id)][] = $name;
            }
        }

        foreach ($voters as &$byOption) {
            foreach ($byOption as &$names) {
                sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            }
        }

        return $voters;
    }

    public function cellVoters(SurveyId $surveyId, array $questionIds): array
    {
        $rows = $this->answers($surveyId, $questionIds)
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->whereNotNull('a.option_id')
            ->whereNotNull('a.row_id')
            ->select(['a.question_id', 'a.row_id', 'a.option_id', 'u.name as user_name', 'r.guest_name'])
            ->get();

        $voters = [];
        foreach ($rows as $row) {
            $name = $row->user_name ?? $row->guest_name;
            if (is_string($name) && $name !== '') {
                $voters[$this->string($row->question_id)][$this->string($row->row_id)][$this->string($row->option_id)][] = $name;
            }
        }

        foreach ($voters as &$byRow) {
            foreach ($byRow as &$byColumn) {
                foreach ($byColumn as &$names) {
                    sort($names, SORT_NATURAL | SORT_FLAG_CASE);
                }
            }
        }

        return $voters;
    }

    public function responseValues(SurveyId $surveyId, array $questionIds): array
    {
        $values = [];
        foreach ($this->answers($surveyId, $questionIds)->select(['a.response_id', 'a.question_id', 'a.option_id', 'a.row_id', 'a.text_value', 'a.number_value'])->get() as $row) {
            $values[$this->string($row->response_id)][] = new AnswerValue(
                $this->string($row->question_id),
                is_string($row->option_id) ? $row->option_id : null,
                is_string($row->row_id) ? $row->row_id : null,
                is_string($row->text_value) ? $row->text_value : null,
                is_numeric($row->number_value) ? (int) $row->number_value : null,
            );
        }

        $responses = [];
        foreach (SurveyResponseModel::query()->where('survey_id', $surveyId->value)->toBase()->get(['id', 'verified']) as $response) {
            $responses[] = ['verified' => (bool) $response->verified, 'values' => $values[$this->string($response->id)] ?? []];
        }

        return $responses;
    }

    /**
     * @return array<string, int>
     */
    private function answeredBy(Builder $answers): array
    {
        $counts = [];
        foreach ($answers->selectRaw('a.question_id, count(distinct a.response_id) as total')->groupBy('a.question_id')->get() as $row) {
            $counts[$this->string($row->question_id)] = $this->int($row->total);
        }

        return $counts;
    }

    /**
     * @param  list<string>  $questionIds
     */
    private function answers(SurveyId $surveyId, array $questionIds): Builder
    {
        return DB::table('surveys_answers as a')
            ->join('surveys_responses as r', 'r.id', '=', 'a.response_id')
            ->where('r.survey_id', $surveyId->value)
            ->whereIn('a.question_id', $questionIds);
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
