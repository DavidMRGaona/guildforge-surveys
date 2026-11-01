import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import TrendChart from '../../resources/js/components/results/TrendChart.vue';
import type { QuestionTrend, TrendPoint } from '../../resources/js/types/Trends';

const charts = vi.hoisted(() => ({
    created: [] as {
        config: {
            type: string;
            data: { labels: string[]; datasets: { label: string; data: (number | null)[] }[] };
            options: {
                indexAxis?: string;
                scales?: Record<string, { min?: number; max?: number; reverse?: boolean }>;
            };
        };
    }[],
}));

// jsdom has no canvas: the chart is replaced by a recorder of what it was asked to draw
vi.mock('chart.js', () => {
    class Chart {
        static register(): void {}

        constructor(_canvas: unknown, config: (typeof charts.created)[number]['config']) {
            charts.created.push({ config });
        }

        destroy(): void {}
    }

    return {
        Chart,
        BarController: {},
        BarElement: {},
        CategoryScale: {},
        Legend: {},
        LinearScale: {},
        LineController: {},
        LineElement: {},
        PointElement: {},
        Tooltip: {},
    };
});

function point(period: string, overrides: Partial<TrendPoint> = {}): TrendPoint {
    return { period, answered: 3, belowMinimum: false, mean: null, shares: {}, ...overrides };
}

function scale(): QuestionTrend {
    return {
        key: 'satisfaction',
        title: '¿Te gustó?',
        type: 'linear_scale',
        measure: 'mean',
        min: 1,
        max: 5,
        periods: [
            { key: '2027-Q1', label: 'T1 2027' },
            { key: '2027-Q2', label: 'T2 2027' },
            { key: '2027-Q3', label: 'T3 2027' },
        ],
        options: [],
        series: [
            {
                label: 'Todas las encuestas',
                points: [
                    point('2027-Q1', { mean: 4.25 }),
                    point('2027-Q2', { answered: 0 }),
                    point('2027-Q3', { answered: 1, belowMinimum: true }),
                ],
            },
        ],
    };
}

function choice(periods: QuestionTrend['periods'], series: QuestionTrend['series']): QuestionTrend {
    return {
        key: 'best_part',
        title: 'Lo mejor',
        type: 'single_choice',
        measure: 'shares',
        min: null,
        max: null,
        periods,
        options: [
            { id: 'o-1', label: 'Historia' },
            { id: 'o-2', label: 'Combate' },
        ],
        series,
    };
}

describe('TrendChart', () => {
    beforeEach(() => {
        charts.created = [];
    });

    it('draws the mean as a line with gaps where there is nothing to show', () => {
        const wrapper = mount(TrendChart, { props: { trend: scale() } });

        const config = charts.created[0]?.config;
        expect(config?.type).toBe('line');
        expect(config?.data.labels).toEqual(['T1 2027', 'T2 2027', 'T3 2027']);
        expect(config?.data.datasets[0]?.data).toEqual([4.25, null, null]);
        expect(config?.options.scales?.y).toMatchObject({ min: 1, max: 5 });

        const cells = wrapper.findAll('tbody td').map((cell) => cell.text());
        expect(cells).toEqual(['4,25', '—', 'Pocas respuestas']);
    });

    it('puts the first place on top of the mean place of a ranked option', () => {
        mount(TrendChart, {
            props: {
                trend: { ...scale(), key: 'games.k-1', type: 'ranking', min: 1, max: 3 },
            },
        });

        expect(charts.created[0]?.config.options.scales?.y).toMatchObject({
            min: 1,
            max: 3,
            reverse: true,
        });
    });

    it('stacks the shares of each period', () => {
        mount(TrendChart, {
            props: {
                trend: choice(
                    [{ key: '2027-Q1', label: 'T1 2027' }],
                    [
                        {
                            label: 'Todas las encuestas',
                            points: [point('2027-Q1', { shares: { 'o-1': 2 / 3, 'o-2': 1 / 3 } })],
                        },
                    ]
                ),
            },
        });

        const config = charts.created[0]?.config;
        expect(config?.type).toBe('bar');
        expect(config?.options.indexAxis).toBe('x');
        expect(config?.data.datasets.map((dataset) => dataset.label)).toEqual([
            'Historia',
            'Combate',
        ]);
        expect(config?.data.datasets.map((dataset) => dataset.data)).toEqual([[66.7], [33.3]]);
    });

    it('draws one bar per series when comparing choices', () => {
        const wrapper = mount(TrendChart, {
            props: {
                trend: choice(
                    [{ key: 'total', label: 'Todo el periodo' }],
                    [
                        {
                            label: 'Rolemaster',
                            points: [point('total', { shares: { 'o-1': 1, 'o-2': 0 } })],
                        },
                        {
                            label: 'Pathfinder',
                            points: [point('total', { answered: 2, belowMinimum: true })],
                        },
                    ]
                ),
            },
        });

        const config = charts.created[0]?.config;
        expect(config?.options.indexAxis).toBe('y');
        expect(config?.data.labels).toEqual(['Rolemaster', 'Pathfinder']);
        expect(config?.data.datasets[0]?.data).toEqual([100, null]);

        const rows = wrapper.findAll('tbody tr').map((row) => row.text());
        expect(rows[0]).toContain('Rolemaster');
        expect(rows[0]).toContain('100 %');
        expect(rows[1]).toContain('Pocas respuestas');
    });

    it('describes the chart for screen readers and keeps a data table', () => {
        const wrapper = mount(TrendChart, { props: { trend: scale() } });

        expect(wrapper.get('[role="img"]').attributes('aria-label')).toContain('¿Te gustó?');
        expect(wrapper.get('[data-testid="data-table"] caption').text()).toBe('¿Te gustó?');
    });
});
