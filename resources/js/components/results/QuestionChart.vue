<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    type ChartConfiguration,
    Legend,
    LinearScale,
    PieController,
    Tooltip,
} from 'chart.js';
import type { ChartType, OrganizerQuestionResult } from '../../types/Results';
import { chartTheme, seriesColor, type ChartTheme } from '../../utils/chartColors';
import { bucketLabel, percent } from '../../utils/buckets';

// Only what these charts draw, so the bundle carries no other chart type
Chart.register(
    BarController,
    BarElement,
    PieController,
    ArcElement,
    CategoryScale,
    LinearScale,
    Tooltip,
    Legend
);

const props = defineProps<{
    question: OrganizerQuestionResult;
    chartType: ChartType;
}>();

const { t, locale } = useI18n();

const canvas = ref<HTMLCanvasElement | null>(null);
let chart: Chart | null = null;
let observer: MutationObserver | null = null;

const labels = computed(() =>
    props.question.buckets.map((bucket) =>
        bucketLabel(props.question.type, bucket.label, t('surveys.results.other'), locale.value)
    )
);

const drawsChart = computed(
    () => !props.question.belowMinimum && props.chartType !== 'list' && props.question.answered > 0
);

// Options read better across; scales, dates and times are a histogram, so they stand up
const horizontal = computed(
    () =>
        props.question.grid !== null ||
        !['linear_scale', 'star_rating', 'date', 'time'].includes(props.question.type)
);

const height = computed(() => {
    if (props.chartType === 'pie') {
        return 260;
    }
    const bars = props.question.grid?.rows.length ?? props.question.buckets.length;

    return horizontal.value ? Math.max(120, bars * 36 + 48) : 240;
});

const summary = computed(() =>
    t('surveys.results.chart_summary', {
        title: props.question.title,
        answered: props.question.answered,
    })
);

function rowTotal(counts: Record<string, number>): number {
    return Object.values(counts).reduce((sum, count) => sum + count, 0);
}

function configuration(theme: ChartTheme): ChartConfiguration {
    const grid = props.question.grid;
    const tooltip = { enabled: true };
    const axes = {
        ticks: { color: theme.text, precision: 0 },
        grid: { color: theme.grid },
        border: { color: theme.grid },
    };

    if (grid !== null) {
        return {
            type: 'bar',
            data: {
                labels: grid.rows.map((row) => row.label),
                datasets: grid.columns.map((column, index) => ({
                    label: column.label,
                    data: grid.rows.map((row) => grid.counts[row.id]?.[column.id] ?? 0),
                    backgroundColor: seriesColor(theme, index),
                    borderColor: theme.surface,
                    borderWidth: { right: 2 },
                })),
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                animation: false,
                scales: {
                    x: { ...axes, stacked: true },
                    y: { ...axes, stacked: true, grid: { display: false } },
                },
                plugins: { legend: { position: 'bottom', labels: { color: theme.text } }, tooltip },
            },
        };
    }

    const counts = props.question.buckets.map((bucket) => bucket.count);

    if (props.chartType === 'pie') {
        return {
            type: 'pie',
            data: {
                labels: labels.value,
                datasets: [
                    {
                        data: counts,
                        backgroundColor: counts.map((_count, index) => seriesColor(theme, index)),
                        borderColor: theme.surface,
                        borderWidth: 2,
                    },
                ],
            },
            options: {
                maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { position: 'bottom', labels: { color: theme.text } }, tooltip },
            },
        };
    }

    // One series: one colour, no legend (the title names it)
    return {
        type: 'bar',
        data: {
            labels: labels.value,
            datasets: [
                {
                    label: t('surveys.results.answers'),
                    data: counts,
                    backgroundColor: seriesColor(theme, 0),
                    borderRadius: 4,
                    borderSkipped: 'start',
                    maxBarThickness: 28,
                },
            ],
        },
        options: {
            indexAxis: horizontal.value ? 'y' : 'x',
            maintainAspectRatio: false,
            animation: false,
            scales: {
                x: horizontal.value ? axes : { ...axes, grid: { display: false } },
                y: horizontal.value ? { ...axes, grid: { display: false } } : axes,
            },
            plugins: { legend: { display: false }, tooltip },
        },
    };
}

function draw(): void {
    chart?.destroy();
    chart = null;

    if (!drawsChart.value || canvas.value === null) {
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
    () => [props.chartType, props.question],
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
        <p v-if="question.belowMinimum" class="text-sm text-base-secondary">
            {{ t('surveys.results.below_minimum') }}
        </p>

        <template v-else-if="chartType === 'list'">
            <p v-if="question.texts.length === 0" class="text-sm text-base-secondary">
                {{ t('surveys.results.no_texts') }}
            </p>
            <ul v-else class="max-h-96 space-y-2 overflow-y-auto" data-testid="texts">
                <li
                    v-for="(text, index) in question.texts"
                    :key="index"
                    class="rounded-md bg-muted px-3 py-2 text-sm break-words whitespace-pre-line text-base-primary"
                >
                    {{ text }}
                </li>
            </ul>
        </template>

        <template v-else>
            <div
                v-if="drawsChart"
                class="relative"
                :style="{ height: `${height}px` }"
                role="img"
                :aria-label="summary"
            >
                <canvas ref="canvas" aria-hidden="true"></canvas>
            </div>

            <!-- The numbers behind the chart, always in view: some colours are faint on the light surface -->
            <div class="overflow-x-auto">
                <table v-if="question.grid" class="min-w-full text-sm" data-testid="data-table">
                    <caption class="sr-only">
                        {{
                            question.title
                        }}
                    </caption>
                    <thead>
                        <tr>
                            <th scope="col"></th>
                            <th
                                v-for="column in question.grid.columns"
                                :key="column.id"
                                scope="col"
                                class="px-2 py-1 font-medium text-base-secondary"
                            >
                                {{ column.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in question.grid.rows" :key="row.id">
                            <th
                                scope="row"
                                class="py-1 pr-3 text-left font-normal text-base-primary"
                            >
                                {{ row.label }}
                            </th>
                            <td
                                v-for="column in question.grid.columns"
                                :key="column.id"
                                class="px-2 py-1 text-center tabular-nums text-base-primary"
                            >
                                {{ question.grid.counts[row.id]?.[column.id] ?? 0 }}
                                <span class="text-base-secondary">
                                    ({{
                                        t('surveys.results.percent', {
                                            percent: percent(
                                                question.grid.counts[row.id]?.[column.id] ?? 0,
                                                rowTotal(question.grid.counts[row.id] ?? {})
                                            ),
                                        })
                                    }})
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table v-else class="min-w-full text-sm" data-testid="data-table">
                    <caption class="sr-only">
                        {{
                            question.title
                        }}
                    </caption>
                    <thead class="sr-only">
                        <tr>
                            <th scope="col">{{ t('surveys.results.answer') }}</th>
                            <th scope="col">{{ t('surveys.results.answers') }}</th>
                            <th scope="col">{{ t('surveys.results.share') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(bucket, index) in question.buckets" :key="bucket.key">
                            <th
                                scope="row"
                                class="py-1 pr-3 text-left font-normal text-base-primary"
                            >
                                {{ labels[index] }}
                                <span
                                    v-if="bucket.voters.length > 0"
                                    class="block text-xs text-base-secondary"
                                >
                                    {{
                                        t('surveys.results.voters', {
                                            names: bucket.voters.join(', '),
                                        })
                                    }}
                                </span>
                            </th>
                            <td class="px-2 py-1 text-right tabular-nums text-base-primary">
                                {{ bucket.count }}
                            </td>
                            <td class="py-1 pl-2 text-right tabular-nums text-base-secondary">
                                {{
                                    t('surveys.results.percent', {
                                        percent: percent(bucket.count, question.answered),
                                    })
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="question.texts.length > 0">
                <h4 class="text-sm font-medium text-base-primary">
                    {{ t('surveys.results.other_answers') }}
                </h4>
                <ul class="mt-2 space-y-1" data-testid="texts">
                    <li
                        v-for="(text, index) in question.texts"
                        :key="index"
                        class="text-sm break-words text-base-secondary"
                    >
                        {{ text }}
                    </li>
                </ul>
            </div>
        </template>
    </div>
</template>
