import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import QuestionChart from '../../resources/js/components/results/QuestionChart.vue';
import type { OrganizerQuestionResult } from '../../resources/js/types/Results';

const charts = vi.hoisted(() => ({
    created: [] as {
        config: {
            type: string;
            data: { labels: string[]; datasets: { data: number[]; backgroundColor: unknown }[] };
            options: { indexAxis?: string };
        };
    }[],
    destroyed: 0,
}));

// jsdom has no canvas: the chart is replaced by a recorder of what it was asked to draw
vi.mock('chart.js', () => {
    class Chart {
        static register(): void {}

        constructor(_canvas: unknown, config: (typeof charts.created)[number]['config']) {
            charts.created.push({ config });
        }

        destroy(): void {
            charts.destroyed++;
        }
    }

    return {
        Chart,
        ArcElement: {},
        BarController: {},
        BarElement: {},
        CategoryScale: {},
        Legend: {},
        LinearScale: {},
        PieController: {},
        Tooltip: {},
    };
});

function question(overrides: Partial<OrganizerQuestionResult> = {}): OrganizerQuestionResult {
    return {
        id: 'q-1',
        title: '¿Repetirías?',
        type: 'single_choice',
        answered: 4,
        belowMinimum: false,
        reached: null,
        buckets: [
            { key: 'si', label: 'Sí', count: 3, voters: [] },
            { key: '__other__', label: null, count: 1, voters: [] },
        ],
        grid: null,
        mean: null,
        median: null,
        ranking: null,
        chartType: 'bar',
        chartTypes: ['bar', 'pie'],
        texts: ['Depende del día'],
        ...overrides,
    };
}

const ranked = {
    positions: 3,
    options: [
        { id: 'k-2', label: 'Wargames', points: 8, meanPosition: 1.33, places: [2, 1, 0] },
        { id: 'k-1', label: 'Rol', points: 6, meanPosition: 2, places: [1, 1, 1] },
        { id: 'k-3', label: 'Tablero', points: 4, meanPosition: 2.67, places: [0, 1, 2] },
    ],
};

describe('QuestionChart', () => {
    beforeEach(() => {
        charts.created = [];
        charts.destroyed = 0;
        document.documentElement.classList.remove('dark');
    });

    it('draws one bar series across with its numbers in a table and the other answers below', () => {
        const wrapper = mount(QuestionChart, { props: { question: question(), chartType: 'bar' } });

        const config = charts.created[0]?.config;
        expect(config?.type).toBe('bar');
        expect(config?.options.indexAxis).toBe('y');
        expect(config?.data.labels).toEqual(['Sí', 'Otra']);
        expect(config?.data.datasets[0]?.data).toEqual([3, 1]);

        const table = wrapper.get('[data-testid="data-table"]').text();
        expect(table).toContain('Sí');
        expect(table).toContain('75 %');
        expect(wrapper.get('[data-testid="texts"]').text()).toContain('Depende del día');
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toContain('¿Repetirías?');
    });

    it('draws the points of a ranking best first, with each mean place in the table', () => {
        const wrapper = mount(QuestionChart, {
            props: {
                question: question({
                    type: 'ranking',
                    answered: 3,
                    buckets: [],
                    texts: [],
                    chartTypes: ['bar', 'stacked_bar'],
                    ranking: ranked,
                }),
                chartType: 'bar',
            },
        });

        const config = charts.created[0]?.config;
        expect(config?.options.indexAxis).toBe('y');
        expect(config?.data.labels).toEqual(['Wargames', 'Rol', 'Tablero']);
        expect(config?.data.datasets[0]?.data).toEqual([8, 6, 4]);

        const rows = wrapper.findAll('[data-testid="data-table"] tbody tr');
        expect(rows.map((row) => row.find('th').text())).toEqual(['Wargames', 'Rol', 'Tablero']);
        expect(rows[0]?.text()).toContain('8');
        expect(rows[0]?.text()).toContain('1,33');
        expect(wrapper.text()).toContain('Cada primer puesto suma 3 puntos');
    });

    it('stacks how often each option of a ranking got each place', () => {
        mount(QuestionChart, {
            props: {
                question: question({
                    type: 'ranking',
                    answered: 3,
                    buckets: [],
                    texts: [],
                    chartType: 'stacked_bar',
                    chartTypes: ['bar', 'stacked_bar'],
                    ranking: ranked,
                }),
                chartType: 'stacked_bar',
            },
        });

        const config = charts.created[0]?.config;
        expect(config?.data.labels).toEqual(['Wargames', 'Rol', 'Tablero']);
        expect(config?.data.datasets).toHaveLength(3);
        expect(config?.data.datasets[0]?.data).toEqual([2, 1, 0]);
        expect(config?.data.datasets[2]?.data).toEqual([0, 1, 2]);
    });

    it('draws a pie with a colour per slice when chosen', async () => {
        const wrapper = mount(QuestionChart, { props: { question: question(), chartType: 'bar' } });
        await wrapper.setProps({ chartType: 'pie' });

        const pie = charts.created.at(-1)?.config;
        expect(pie?.type).toBe('pie');
        expect(pie?.data.datasets[0]?.backgroundColor).toEqual(['#2a78d6', '#eb6834']);
        expect(charts.destroyed).toBeGreaterThan(0);
    });

    it('stands a scale up as a histogram', () => {
        mount(QuestionChart, {
            props: {
                question: question({
                    type: 'linear_scale',
                    chartTypes: ['bar'],
                    texts: [],
                    buckets: [
                        { key: '1', label: '1', count: 0, voters: [] },
                        { key: '2', label: '2', count: 2, voters: [] },
                    ],
                }),
                chartType: 'bar',
            },
        });

        expect(charts.created[0]?.config.options.indexAxis).toBe('x');
    });

    it('stacks a grid by column', () => {
        const wrapper = mount(QuestionChart, {
            props: {
                question: question({
                    type: 'grid',
                    chartType: 'stacked_bar',
                    chartTypes: ['stacked_bar'],
                    texts: [],
                    buckets: [],
                    grid: {
                        rows: [{ id: 'r-1', label: 'Reglas' }],
                        columns: [
                            { id: 'c-1', label: 'Bien' },
                            { id: 'c-2', label: 'Mal' },
                        ],
                        counts: { 'r-1': { 'c-1': 3, 'c-2': 1 } },
                    },
                }),
                chartType: 'stacked_bar',
            },
        });

        const config = charts.created[0]?.config;
        expect(config?.data.labels).toEqual(['Reglas']);
        expect(config?.data.datasets.map((dataset) => dataset.data)).toEqual([[3], [1]]);
        expect(wrapper.get('[data-testid="data-table"]').text()).toContain('Bien');
    });

    it('takes the dark steps when the theme switches', async () => {
        mount(QuestionChart, { props: { question: question(), chartType: 'pie' } });

        document.documentElement.classList.add('dark');
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(charts.created.at(-1)?.config.data.datasets[0]?.backgroundColor).toEqual([
            '#3987e5',
            '#d95926',
        ]);
    });

    it('lists free text without a chart', () => {
        const wrapper = mount(QuestionChart, {
            props: {
                question: question({
                    type: 'paragraph',
                    chartType: 'list',
                    chartTypes: ['list'],
                    buckets: [],
                    texts: ['Muy bien', 'Mejor la próxima'],
                }),
                chartType: 'list',
            },
        });

        expect(charts.created).toHaveLength(0);
        expect(wrapper.findAll('[data-testid="texts"] li')).toHaveLength(2);
    });

    it('says when a question has too few answers to show', () => {
        const wrapper = mount(QuestionChart, {
            props: {
                question: question({ belowMinimum: true, buckets: [], texts: [] }),
                chartType: 'bar',
            },
        });

        expect(charts.created).toHaveLength(0);
        expect(wrapper.text()).toContain('Aún no hay respuestas suficientes');
    });
});
