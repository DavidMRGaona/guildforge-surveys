import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import type { VNode } from 'vue';
import { router } from '@inertiajs/vue3';
import Trends from '../../resources/js/pages/Surveys/Manage/Trends.vue';
import type { TrendsPageProps } from '../../resources/js/types/Trends';

vi.mock('../../resources/js/components/results/TrendChart.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'TrendChart',
            props: { trend: { type: Object, required: true } },
            setup() {
                return (): VNode => h('div', { 'data-chart': 'trend' });
            },
        }),
    };
});

function props(overrides: Partial<TrendsPageProps> = {}): TrendsPageProps {
    return {
        templates: [
            { id: 't-1', name: 'Valoración de la partida' },
            { id: 't-2', name: 'Valoración del torneo' },
        ],
        templateId: 't-1',
        filters: {
            type: null,
            system: 'Rolemaster',
            from: null,
            to: null,
            period: 'quarter',
            compare: 'none',
        },
        options: {
            subjectTypes: [{ value: 'game-table', label: 'Mesa' }],
            gameSystems: ['Rolemaster'],
        },
        trends: {
            visible: true,
            surveys: 2,
            responses: 7,
            questions: [
                {
                    key: 'satisfaction',
                    title: '¿Te gustó?',
                    type: 'linear_scale',
                    measure: 'mean',
                    min: 1,
                    max: 5,
                    periods: [{ key: '2027-Q1', label: 'T1 2027' }],
                    options: [],
                    series: [
                        {
                            label: 'Todas las encuestas',
                            points: [
                                {
                                    period: '2027-Q1',
                                    answered: 7,
                                    belowMinimum: false,
                                    mean: 4,
                                    shares: {},
                                },
                            ],
                        },
                    ],
                },
                {
                    key: 'comment',
                    title: 'Comentarios',
                    type: 'paragraph',
                    measure: 'none',
                    min: null,
                    max: null,
                    periods: [],
                    options: [],
                    series: [],
                },
            ],
        },
        minimum: 3,
        url: '/encuestas/tendencias',
        ...overrides,
    };
}

describe('Trends', () => {
    it('shows the summary and one chart per question with a trend', () => {
        const wrapper = mount(Trends, { props: props() });

        expect(wrapper.get('[data-testid="summary"]').text()).toContain('2 encuestas');
        expect(wrapper.get('[data-testid="summary"]').text()).toContain('7 respuestas');
        expect(wrapper.findAll('[data-chart="trend"]')).toHaveLength(1);
        expect(wrapper.get('[data-question="comment"]').text()).toContain('no tienen tendencia');
        expect(wrapper.text()).toContain('con menos de 3');
    });

    it('visits the page with the new cut', async () => {
        const wrapper = mount(Trends, { props: props() });

        const period = wrapper.findAllComponents({ name: 'FormSelect' }).at(3);
        period?.vm.$emit('update:modelValue', 'month');
        await wrapper.vm.$nextTick();

        expect(router.get).toHaveBeenLastCalledWith(
            '/encuestas/tendencias',
            { template: 't-1', system: 'Rolemaster', period: 'month', compare: 'none' },
            expect.objectContaining({ preserveState: true })
        );
    });

    it('drops the filters of the last template when another one is chosen', async () => {
        const wrapper = mount(Trends, { props: props() });

        wrapper
            .findAllComponents({ name: 'FormSelect' })
            .at(0)
            ?.vm.$emit('update:modelValue', 't-2');
        await wrapper.vm.$nextTick();

        expect(router.get).toHaveBeenLastCalledWith(
            '/encuestas/tendencias',
            { template: 't-2', period: 'quarter', compare: 'none' },
            expect.anything()
        );
    });

    it('says so below the minimum', () => {
        const wrapper = mount(Trends, {
            props: props({ trends: { visible: false, surveys: 1, responses: 2, questions: [] } }),
        });

        expect(wrapper.get('[data-testid="below-minimum"]').text()).toContain(
            'no hay respuestas suficientes'
        );
    });

    it('has an empty state without templates', () => {
        const wrapper = mount(Trends, {
            props: props({ templates: [], templateId: null, trends: null }),
        });

        expect(wrapper.text()).toContain('Aún no hay tendencias');
        expect(wrapper.find('[data-testid="filters"]').exists()).toBe(false);
    });
});
