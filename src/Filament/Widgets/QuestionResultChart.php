<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Widgets;

use DateTimeImmutable;
use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;
use Modules\Surveys\Application\DTOs\Results\GridResultDTO;
use Modules\Surveys\Application\DTOs\Results\QuestionResultDTO;
use Modules\Surveys\Application\DTOs\Results\RankedOptionDTO;
use Modules\Surveys\Application\DTOs\Results\RankingResultDTO;
use Modules\Surveys\Application\DTOs\Results\ResultBucketDTO;
use Modules\Surveys\Application\Services\ResultsService;
use Modules\Surveys\Domain\Enums\ChartType;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\SurveyId;
use Modules\Surveys\Filament\Support\ChartPalette;
use Modules\Surveys\Infrastructure\Persistence\Eloquent\Models\SurveyModel;

/**
 * The chart of one question on the results page. Not a dashboard widget: the results page
 * renders one per question. The ids are locked and checked again on mount, because a
 * Livewire component can be mounted with any parameters.
 */
final class QuestionResultChart extends ChartWidget
{
    #[Locked]
    public string $surveyId = '';

    #[Locked]
    public string $questionId = '';

    protected ?string $maxHeight = '320px';

    private ?QuestionResultDTO $result = null;

    private bool $loaded = false;

    public function mount(): void
    {
        $survey = SurveyModel::query()->find($this->surveyId);
        abort_unless($survey instanceof SurveyModel && auth()->user()?->can('viewResults', $survey) === true, 403);

        parent::mount();
    }

    protected function getType(): string
    {
        return $this->result()?->chartType === ChartType::Pie ? 'pie' : 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $result = $this->result();

        if (! $result instanceof QuestionResultDTO || $result->belowMinimum) {
            return ['datasets' => [], 'labels' => []];
        }

        if ($result->ranking instanceof RankingResultDTO) {
            return $this->rankingData($result->chartType, $result->ranking);
        }

        if ($result->grid instanceof GridResultDTO) {
            $datasets = [];
            foreach ($result->grid->columns as $index => $column) {
                $datasets[] = [
                    'label' => $column->label,
                    'data' => array_map(static fn ($row): int => $result->grid->counts[$row->id][$column->id] ?? 0, $result->grid->rows),
                    'backgroundColor' => $this->color($index),
                    'borderColor' => $this->color($index),
                ];
            }

            return ['datasets' => $datasets, 'labels' => array_map(static fn ($row): string => $row->label, $result->grid->rows)];
        }

        $colors = $result->chartType === ChartType::Pie
            ? array_map($this->color(...), array_keys($result->buckets))
            : $this->color(0);

        return [
            'datasets' => [[
                'label' => __('surveys::filament.results.series'),
                'data' => array_map(static fn (ResultBucketDTO $bucket): int => $bucket->count, $result->buckets),
                'backgroundColor' => $colors,
                'borderColor' => $colors,
            ]],
            'labels' => array_map(fn (ResultBucketDTO $bucket): string => $this->label($result->type, $bucket), $result->buckets),
        ];
    }

    /**
     * Null rather than [] when there is nothing to set: an empty array reaches Chart.js as the
     * JSON list [], which it cannot read as options, and Filament turns null into {}.
     *
     * @return array<string, mixed>|null
     */
    protected function getOptions(): ?array
    {
        $result = $this->result();
        $wholeNumbers = ['ticks' => ['precision' => 0]];

        return match (true) {
            ! $result instanceof QuestionResultDTO, $result->chartType === ChartType::Pie => null,
            $result->chartType === ChartType::StackedBar => [
                'indexAxis' => 'y',
                'scales' => ['x' => ['stacked' => true, ...$wholeNumbers], 'y' => ['stacked' => true]],
            ],
            // Options read best as horizontal bars; scales, dates and times as a histogram
            $result->type->hasOptions() => ['indexAxis' => 'y', 'plugins' => ['legend' => ['display' => false]], 'scales' => ['x' => $wholeNumbers]],
            default => ['plugins' => ['legend' => ['display' => false]], 'scales' => ['y' => $wholeNumbers]],
        };
    }

    /**
     * Each option's Borda points, best first; as stacked bars, how often it got each place.
     *
     * @return array<string, mixed>
     */
    private function rankingData(ChartType $chartType, RankingResultDTO $ranking): array
    {
        $labels = array_map(static fn (RankedOptionDTO $option): string => $option->label, $ranking->options);

        if ($chartType !== ChartType::StackedBar) {
            return [
                'datasets' => [[
                    'label' => __('surveys::filament.results.points'),
                    'data' => array_map(static fn (RankedOptionDTO $option): int => $option->points, $ranking->options),
                    'backgroundColor' => $this->color(0),
                    'borderColor' => $this->color(0),
                ]],
                'labels' => $labels,
            ];
        }

        $datasets = [];
        foreach (range(1, $ranking->positions) as $index => $place) {
            $datasets[] = [
                'label' => __('surveys::filament.results.place', ['place' => $place]),
                'data' => array_map(static fn (RankedOptionDTO $option): int => $option->positions[$place] ?? 0, $ranking->options),
                'backgroundColor' => $this->color($index),
                'borderColor' => $this->color($index),
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    private function result(): ?QuestionResultDTO
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $results = app(ResultsService::class)->forPanel(SurveyId::fromString($this->surveyId), [$this->questionId]);
            $this->result = $results->questions[0] ?? null;
        }

        return $this->result;
    }

    private function label(QuestionType $type, ResultBucketDTO $bucket): string
    {
        if ($bucket->label === null) {
            return __('surveys::filament.results.other');
        }

        if ($type === QuestionType::Date) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $bucket->label);

            return $date instanceof DateTimeImmutable ? $date->format('d/m/Y') : $bucket->label;
        }

        return $bucket->label;
    }

    private function color(int $index): string
    {
        return ChartPalette::color($index);
    }
}
