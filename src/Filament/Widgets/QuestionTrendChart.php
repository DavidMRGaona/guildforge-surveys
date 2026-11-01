<?php

declare(strict_types=1);

namespace Modules\Surveys\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Livewire\Attributes\Locked;
use Modules\Surveys\Application\DTOs\Trends\QuestionTrendDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendFilters;
use Modules\Surveys\Application\DTOs\Trends\TrendPointDTO;
use Modules\Surveys\Application\DTOs\Trends\TrendSeriesDTO;
use Modules\Surveys\Application\Services\TrendsService;
use Modules\Surveys\Domain\Enums\QuestionType;
use Modules\Surveys\Domain\ValueObjects\SurveyTemplateId;
use Modules\Surveys\Filament\Pages\SurveyTrendsPage;
use Modules\Surveys\Filament\Support\ChartPalette;

/**
 * The trend of one question on the trends page: a line per series for scales, stacked shares
 * for choices and grid rows. Not a dashboard widget. Its props are locked and access is checked
 * again on mount, because a Livewire component can be mounted with any parameters.
 */
final class QuestionTrendChart extends ChartWidget
{
    #[Locked]
    public string $templateId = '';

    #[Locked]
    public string $questionKey = '';

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $filters = [];

    protected ?string $maxHeight = '320px';

    private ?QuestionTrendDTO $trend = null;

    private bool $loaded = false;

    public function mount(): void
    {
        abort_unless(SurveyTrendsPage::canAccess() && in_array($this->templateId, array_column(app(TrendsService::class)->templatesFor(), 'id'), true), 403);

        parent::mount();
    }

    protected function getType(): string
    {
        return $this->trend()?->measure === QuestionTrendDTO::MEAN ? 'line' : 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $trend = $this->trend();

        if (! $trend instanceof QuestionTrendDTO || $trend->series === []) {
            return ['datasets' => [], 'labels' => []];
        }

        if ($trend->measure === QuestionTrendDTO::MEAN) {
            $datasets = [];
            foreach ($trend->series as $index => $series) {
                $datasets[] = [
                    'label' => $series->label,
                    // A gap, not a zero, where a period has no answers or too few to show
                    'data' => array_map(static fn (TrendPointDTO $point): ?float => $point->belowMinimum ? null : $point->mean, $series->points),
                    'borderColor' => ChartPalette::color($index),
                    'backgroundColor' => ChartPalette::color($index),
                    'spanGaps' => false,
                ];
            }

            return ['datasets' => $datasets, 'labels' => array_column($trend->periods, 'label')];
        }

        // Shares compared by series: one stacked bar per series over the whole range
        $byTotal = array_column($trend->periods, 'key') === [QuestionTrendDTO::TOTAL];
        $points = $byTotal
            ? array_map(static fn (TrendSeriesDTO $series): ?TrendPointDTO => $series->points[0] ?? null, $trend->series)
            : $trend->series[0]->points;

        $datasets = [];
        foreach ($trend->options as $index => $option) {
            $datasets[] = [
                'label' => $option['label'],
                'data' => array_map(static fn (?TrendPointDTO $point): ?float => $point instanceof TrendPointDTO && ! $point->belowMinimum && $point->shares !== []
                    ? round(($point->shares[$option['id']] ?? 0.0) * 100, 1)
                    : null, $points),
                'backgroundColor' => ChartPalette::color($index),
                'borderColor' => ChartPalette::color($index),
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $byTotal ? array_map(static fn (TrendSeriesDTO $series): string => $series->label, $trend->series) : array_column($trend->periods, 'label'),
        ];
    }

    /**
     * Never [] (it reaches Chart.js as a JSON list, which it cannot read as options): null when
     * there is nothing to set, and Filament turns it into {}.
     *
     * @return array<string, mixed>|null
     */
    protected function getOptions(): ?array
    {
        $trend = $this->trend();
        $percent = ['min' => 0, 'max' => 100, 'stacked' => true];

        return match (true) {
            ! $trend instanceof QuestionTrendDTO, $trend->series === [] => null,
            // A mean place reads best with the first place on top
            $trend->measure === QuestionTrendDTO::MEAN && $trend->type === QuestionType::Ranking => ['scales' => ['y' => ['min' => $trend->min, 'max' => $trend->max, 'ticks' => ['precision' => 0], 'reverse' => true]]],
            $trend->measure === QuestionTrendDTO::MEAN => ['scales' => ['y' => ['min' => $trend->min, 'max' => $trend->max, 'ticks' => ['precision' => 0]]]],
            array_column($trend->periods, 'key') === [QuestionTrendDTO::TOTAL] => ['indexAxis' => 'y', 'scales' => ['x' => $percent, 'y' => ['stacked' => true]]],
            default => ['scales' => ['x' => ['stacked' => true], 'y' => $percent]],
        };
    }

    private function trend(): ?QuestionTrendDTO
    {
        if (! $this->loaded) {
            $this->loaded = true;
            $trends = app(TrendsService::class)->forPanel(SurveyTemplateId::fromString($this->templateId), TrendFilters::fromInput($this->filters), $this->questionKey);
            $this->trend = $trends->questions[0] ?? null;
        }

        return $this->trend;
    }
}
