<?php

declare(strict_types=1);

namespace Modules\Surveys\Infrastructure\Export;

use App\Application\Services\TimezoneServiceInterface;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Surveys\Application\Contracts\SurveyResultsQueryInterface;
use Modules\Surveys\Application\Contracts\SurveySettingsInterface;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Domain\Entities\Survey;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\Exceptions\ResultsNotAvailableException;
use Modules\Surveys\Domain\Repositories\SurveyRepositoryInterface;
use Modules\Surveys\Domain\ValueObjects\Definition\GridSettings;
use Modules\Surveys\Domain\ValueObjects\Definition\Option;
use Modules\Surveys\Domain\ValueObjects\Definition\Question;
use Modules\Surveys\Domain\ValueObjects\SurveyId;

/**
 * A survey's responses as a table: one row per response, ordered by its random id, and one
 * column per question (a grid has one per row). The person only on named surveys; on anonymous
 * ones the same rules as the results page apply, the minimum per question included.
 */
final readonly class SurveyExportTable
{
    private const int BATCH = 200;

    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyResultsQueryInterface $query,
        private ResultsService $results,
        private SurveySettingsInterface $settings,
        private TimezoneServiceInterface $timezone,
    ) {}

    /**
     * @throws ResultsNotAvailableException while an anonymous survey cannot show its results
     */
    public function build(SurveyId $id, ?DateTimeImmutable $now = null): ExportTable
    {
        $survey = $this->surveys->findOrFail($id);
        $reason = $this->results->panelHiddenReason($survey, $this->query->responseCount($id), $now ?? now()->toDateTimeImmutable());

        if ($reason !== null) {
            throw ResultsNotAvailableException::for($id->value, $reason);
        }

        /** @var list<array{Question, ?Option}> $columns */
        $columns = [];
        $headers = [__('surveys::messages.export.date')];
        if (! $survey->isAnonymous()) {
            $headers[] = __('surveys::messages.export.person');
        }

        foreach ($survey->definition()->questions() as $question) {
            if ($question->type === QuestionType::Grid && $question->grid !== null) {
                foreach ($question->grid->rows as $row) {
                    $columns[] = [$question, $row];
                    $headers[] = "{$question->title}: {$row->label}";
                }

                continue;
            }

            $columns[] = [$question, null];
            $headers[] = $question->title;
        }

        return new ExportTable(
            array_map(static fn (mixed $header): string => is_string($header) ? $header : '', $headers),
            $this->rows($survey, $columns),
            Str::slug($survey->title()).'-'.$this->timezone->today()->format('Y-m-d'),
        );
    }

    /**
     * @param  list<array{Question, ?Option}>  $columns
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(Survey $survey, array $columns): Generator
    {
        $named = ! $survey->isAnonymous();
        $withheld = $this->withheldQuestions($survey);

        $responses = DB::table('surveys_responses as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.survey_id', $survey->id()->value)
            ->orderBy('r.id')
            ->select(['r.id', 'r.submitted_on', 'u.name as user_name', 'r.guest_name'])
            ->lazy(self::BATCH);

        $batch = [];
        foreach ($responses as $response) {
            $batch[] = $response;

            if (count($batch) === self::BATCH) {
                yield from $this->batch($batch, $columns, $named, $withheld);
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield from $this->batch($batch, $columns, $named, $withheld);
        }
    }

    /**
     * @param  list<object>  $responses
     * @param  list<array{Question, ?Option}>  $columns
     * @param  list<string>  $withheld
     * @return Generator<int, list<string|int|null>>
     */
    private function batch(array $responses, array $columns, bool $named, array $withheld): Generator
    {
        $ids = array_map(static fn (object $response): string => self::string($response->id ?? null), $responses);
        $answers = [];
        foreach (DB::table('surveys_answers')->whereIn('response_id', $ids)->orderBy('id')->get() as $answer) {
            $answers[self::string($answer->response_id)][self::string($answer->question_id)][] = $answer;
        }

        foreach ($responses as $response) {
            $responseId = self::string($response->id ?? null);
            $row = [self::string($response->submitted_on ?? null)];

            if ($named) {
                $name = $response->user_name ?? $response->guest_name ?? null;
                $row[] = is_string($name) ? $name : null;
            }

            foreach ($columns as [$question, $gridRow]) {
                $row[] = in_array($question->id, $withheld, true)
                    ? null
                    : $this->value($question, $gridRow, $answers[$responseId][$question->id] ?? []);
            }

            yield $row;
        }
    }

    /**
     * @param  list<object>  $answers
     */
    private function value(Question $question, ?Option $gridRow, array $answers): string|int|null
    {
        if ($answers === []) {
            return null;
        }

        if ($gridRow instanceof Option && $question->grid instanceof GridSettings) {
            foreach ($answers as $answer) {
                if (($answer->row_id ?? null) === $gridRow->id) {
                    return $this->label($question->grid->columns, self::string($answer->option_id ?? null));
                }
            }

            return null;
        }

        if ($question->type === QuestionType::LinearScale || $question->type === QuestionType::StarRating) {
            $number = $answers[0]->number_value ?? null;

            return is_numeric($number) ? (int) $number : null;
        }

        if ($question->type->hasOptions()) {
            // In the order of the definition, "Other" last: never the random order of the answer ids
            $chosenIds = array_map(static fn (object $answer): ?string => is_string($answer->option_id ?? null) ? $answer->option_id : null, $answers);
            $labels = [];
            foreach ($question->options as $option) {
                if (in_array($option->id, $chosenIds, true)) {
                    $labels[] = $option->label;
                }
            }

            foreach ($answers as $answer) {
                if (($answer->option_id ?? null) === null) {
                    $other = __('surveys::messages.export.other', ['text' => self::string($answer->text_value ?? null)]);
                    $labels[] = is_string($other) ? $other : '';
                }
            }

            return implode('; ', $labels);
        }

        $text = $answers[0]->text_value ?? null;

        return is_string($text) ? $text : null;
    }

    /**
     * Questions of an anonymous survey too few people answered: their column stays empty.
     *
     * @return list<string>
     */
    private function withheldQuestions(Survey $survey): array
    {
        $minimum = $survey->isAnonymous() ? $this->settings->minResponses() : 0;
        if ($minimum === 0) {
            return [];
        }

        $ids = array_map(static fn (Question $question): string => $question->id, $survey->definition()->questions());
        $answered = $this->query->answeredCounts($survey->id(), $ids);

        return array_values(array_filter($ids, static fn (string $id): bool => ($answered[$id] ?? 0) < $minimum));
    }

    /**
     * @param  list<Option>  $options
     */
    private function label(array $options, string $id): string
    {
        foreach ($options as $option) {
            if ($option->id === $id) {
                return $option->label;
            }
        }

        return $id;
    }

    private static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
