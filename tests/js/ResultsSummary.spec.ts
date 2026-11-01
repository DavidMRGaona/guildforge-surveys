import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import Respond from '../../resources/js/pages/Surveys/Respond.vue';
import ResultsSummary from '../../resources/js/components/ResultsSummary.vue';
import type { QuestionResult, RespondResults } from '../../resources/js/types/Respond';

function question(overrides: Partial<QuestionResult> = {}): QuestionResult {
    return {
        id: 'q-1',
        title: '¿Qué día?',
        type: 'single_choice',
        answered: 4,
        belowMinimum: false,
        buckets: [
            {
                key: 'o-1',
                label: 'Viernes',
                count: 3,
                voters: ['Persona A', 'Persona B', 'Persona C'],
            },
            { key: 'o-2', label: 'Sábado', count: 1, voters: ['Persona D'] },
            { key: '__other__', label: null, count: 0, voters: [] },
        ],
        grid: null,
        mean: null,
        median: null,
        reached: null,
        ...overrides,
    };
}

function results(questions: QuestionResult[]): RespondResults {
    return { responses: 4, questions };
}

describe('ResultsSummary', () => {
    it('draws a bar per option with its share and who voted', () => {
        const wrapper = mount(ResultsSummary, { props: { results: results([question()]) } });

        const bars = wrapper.findAll('[role="progressbar"]');
        expect(bars).toHaveLength(3);
        expect(bars[0]?.attributes('aria-valuenow')).toBe('75');
        expect(wrapper.text()).toContain('Viernes');
        expect(wrapper.text()).toContain('Otra');
        expect(wrapper.text()).toContain('Han votado: Persona A, Persona B, Persona C');
        expect(wrapper.text()).toContain('4 respuestas');
    });

    it('says how many reached a question some sections skipped', () => {
        const wrapper = mount(ResultsSummary, {
            props: {
                results: results([question(), question({ id: 'q-2', answered: 2, reached: 3 })]),
            },
        });

        const [all, skipped] = wrapper.findAll('article');
        expect(all?.text()).not.toContain('La vieron');
        expect(skipped?.text()).toContain('La vieron 3 de 4');
    });

    it('shows the mean and median of a scale and hides a question below the minimum', () => {
        const wrapper = mount(ResultsSummary, {
            props: {
                results: results([
                    question({
                        id: 'q-2',
                        type: 'linear_scale',
                        buckets: [
                            { key: '1', label: '1', count: 1, voters: [] },
                            { key: '5', label: '5', count: 3, voters: [] },
                        ],
                        mean: 4,
                        median: 5,
                    }),
                    question({
                        id: 'q-3',
                        title: 'Opcional',
                        answered: 1,
                        belowMinimum: true,
                        buckets: [],
                    }),
                ]),
            },
        });

        expect(wrapper.text()).toContain('Media: 4 · Mediana: 5');
        expect(wrapper.text()).toContain('Aún no hay respuestas suficientes a esta pregunta');
        expect(wrapper.findAll('article')[1]?.find('[role="progressbar"]').exists()).toBe(false);
    });

    it('appears on the response page after answering, not before', () => {
        const survey = {
            id: 's-1',
            kind: 'poll' as const,
            title: 'Sondeo',
            description: null,
            anonymous: false,
            closesAt: null,
        };
        const answered = mount(Respond, {
            props: { state: 'answered', result: null, survey, results: results([question()]) },
        });
        expect(answered.text()).toContain('Resultados');

        const notShared = mount(Respond, { props: { state: 'answered', result: null, survey } });
        expect(notShared.text()).not.toContain('Resultados');
    });
});
