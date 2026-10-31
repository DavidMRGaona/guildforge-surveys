<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    type ChartConfiguration,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';
import { TOTAL_PERIOD, type QuestionTrend, type TrendPoint } from '../../types/Trends';
import { chartTheme, seriesColor, type ChartTheme } from '../../utils/chartColors';

Chart.register(
    BarController,
    BarElement,
    LineController,
    LineElement,
    PointElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend
);

const props = defineProps<{ trend: QuestionTrend }>();

const { t, locale } = useI18n();

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: Chart | null = null;
let observer: MutationObserver | null = null;

/** Choices compared by series: one stacked bar per series over the whole range. */
const byTotal = computed(
    () => props.trend.periods.length === 1 && props.trend.periods[0]?.key === TOTAL_PERIOD
);

/**
 * The data table: for scales, a row per period and a column per series; for shares, a row per
 * period (or per series over the whole range) and a column per option.
 */
const rows = computed(() => {
    if (props.trend.measure === 'mean') {
        return props.trend.periods.map((period, index) => ({
            label: period.label,
            points: props.trend.series.map((series) => series.points[index]),
        }));
    }

    return byTotal.value
        ? props.trend.series.map((series) => ({ label: series.label, points: [series.points[0]] }))
        : props.trend.periods.map((period, index) => ({
              label: period.label,
              points: [props.trend.series[0]?.points[index]],
          }));
});

const columns = computed(() =>
    props.trend.measure === 'mean'
        ? props.trend.series.map((series) => series.label)
        : props.trend.options.map((option) => option.label)
);

const summary = computed(() => t('surveys.trends.chart_summary', { title: props.trend.title }));

function shown(point: TrendPoint | undefined): point is TrendPoint {
    return point !== undefined && !point.belowMinimum && point.answered > 0;
}

function share(point: TrendPoint | undefined, id: string): number | null {
    return shown(point) ? Math.round((point.shares[id] ?? 0) * 1000) / 10 : null;
}

function number(value: number): string {
    return new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(value);
}

/** The cells of one table row, already formatted. */
function cells(points: (TrendPoint | undefined)[]): string[] {
    if (props.trend.measure === 'mean') {
        return points.map((point) =>
            cell(
                point,
                point?.mean === null || point?.mean === undefined ? '—' : number(point.mean)
            )
        );
    }

    const point = points[0];

    return props.trend.options.map((option) => {
        const value = share(point, option.id);

        return cell(
            point,
            value === null ? '—' : t('surveys.results.percent', { percent: number(value) })
        );
    });
}

function cell(point: TrendPoint | undefined, value: string): string {
    if (point === undefined || point.answered === 0) {
        return '—';
    }

    return point.belowMinimum ? t('surveys.trends.few') : value;
}

function configuration(theme: ChartTheme): ChartConfiguration {
    const axes = {
        ticks: { color: theme.text },
        grid: { color: theme.grid },
        border: { color: theme.grid },
    };
    const legend = { position: 'bottom' as const, labels: { color: theme.text } };

    if (props.trend.measure === 'mean') {
        return {
            type: 'line',
            data: {
                labels: props.trend.periods.map((period) => period.label),
                datasets: props.trend.series.map((series, index) => ({
                    label: series.label,
                    // A gap, not a zero, where a period has no answers or too few to show
                    data: series.points.map((point) => (shown(point) ? point.mean : null)),
                    borderColor: seriesColor(theme, index),
                    backgroundColor: seriesColor(theme, index),
                    pointRadius: 4,
                    spanGaps: false,
                })),
            },
            options: {
                maintainAspectRatio: false,
                animation: false,
                scales: {
                    x: { ...axes, grid: { display: false } },
                    y: {
                        ...axes,
                        min: props.trend.min ?? undefined,
                        max: props.trend.max ?? undefined,
                        ticks: { ...axes.ticks, precision: 0 },
                    },
                },
                plugins: { legend: { ...legend, display: props.trend.series.length > 1 } },
            },
        };
    }

    const points = byTotal.value
        ? props.trend.series.map((series) => series.points[0])
        : (props.trend.series[0]?.points ?? []);
    const percent = { ...axes, stacked: true, min: 0, max: 100 };

    return {
        type: 'bar',
        data: {
            labels: byTotal.value
                ? props.trend.series.map((series) => series.label)
                : props.trend.periods.map((period) => period.label),
            datasets: props.trend.options.map((option, index) => ({
                label: option.label,
                data: points.map((point) => share(point, option.id)),
                backgroundColor: seriesColor(theme, index),
                borderColor: theme.surface,
                borderWidth: 1,
                maxBarThickness: 40,
            })),
        },
        options: {
            indexAxis: byTotal.value ? 'y' : 'x',
            maintainAspectRatio: false,
            animation: false,
            scales: byTotal.value
                ? { x: percent, y: { ...axes, stacked: true, grid: { display: false } } }
                : { x: { ...axes, stacked: true, grid: { display: false } }, y: percent },
            plugins: { legend },
        },
    };
}

function draw(): void {
    chart?.destroy();
    chart = null;

    if (canvas.value === null || props.trend.series.length === 0) {
        return;
    }

    chart = new Chart(canvas.value, configuration(chartTheme()));
}

onMounted(() => {
    draw();
    // The theme switch toggles a class on <html>: the chart takes the new colours
    observer = new MutationObserver(() => draw());
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
});

watch(
    () => props.trend,
    () => draw(),
    { flush: 'post' }
);

onBeforeUnmount(() => {
    observer?.disconnect();
    chart?.destroy();
    chart = null;
});
</script>

<template>
    <div class="space-y-4">
        <div class="relative h-72" role="img" :aria-label="summary">
            <canvas ref="canvas" aria-hidden="true"></canvas>
        </div>

        <!-- The numbers behind the chart, always in view: some colours are faint on the light surface -->
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm" data-testid="data-table">
                <caption class="sr-only">
                    {{
                        trend.title
                    }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col" class="py-1 pr-3 text-left font-medium text-base-secondary">
                            {{
                                byTotal
                                    ? t('surveys.trends.series_column')
                                    : t('surveys.trends.period_column')
                            }}
                        </th>
                        <th
                            v-for="(column, index) in columns"
                            :key="index"
                            scope="col"
                            class="px-2 py-1 text-right font-medium text-base-secondary"
                        >
                            {{ column }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="index"
                        class="border-t border-neutral-200"
                    >
                        <th scope="row" class="py-1 pr-3 text-left font-normal text-base-primary">
                            {{ row.label }}
                        </th>
                        <td
                            v-for="(value, column) in cells(row.points)"
                            :key="column"
                            class="px-2 py-1 text-right tabular-nums text-base-primary"
                        >
                            {{ value }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
