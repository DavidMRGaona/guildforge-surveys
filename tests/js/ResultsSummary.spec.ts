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
        ranking: null,
        availability: null,
        reached: null,
        ...overrides,
    };
}

function results(questions: QuestionResult[]): RespondResults {
    return { responses: 4, questions };
}

const ranked = {
    positions: 3,
    options: [
        { id: 'k-2', label: 'Wargames', points: 8, meanPosition: 1.33, places: [2, 1, 0] },
        { id: 'k-1', label: 'Rol', points: 6, meanPosition: 2, places: [1, 1, 1] },
        { id: 'k-3', label: 'Tablero', points: 4, meanPosition: 2.67, places: [0, 1, 2] },
    ],
};

const availability = {
    chosenSlotId: null,
    slots: [
        {
            id: 'd-1',
            label: 'sábado, 12 de enero de 2030',
            shortLabel: 'sáb 12 ene',
            yes: 2,
            ifNeeded: 1,
            no: 0,
            best: true,
            yesNames: ['Persona A', 'Persona B'],
            ifNeededNames: ['Persona C'],
            noNames: [],
        },
        {
            id: 'd-2',
            label: 'domingo, 13 de enero de 2030, de 10:00 a 14:00',
            shortLabel: 'dom 13 ene · 10:00–14:00',
            yes: 1,
            ifNeeded: 0,
            no: 2,
            best: false,
            yesNames: [],
            ifNeededNames: [],
            noNames: [],
        },
    ],
};

describe('ResultsSummary', () => {
    it('lists each proposed date with its answers, the best marked and who can', () => {
        const wrapper = mount(ResultsSummary, {
            props: {
                results: results([
                    question({ type: 'availability', answered: 3, buckets: [], availability }),
                ]),
            },
        });

        const first = wrapper.get('[data-slot="d-1"]');
        expect(first.text()).toContain('sábado, 12 de enero de 2030');
        expect(first.text()).toContain('2 sí · 1 si hace falta · 0 no');
        expect(first.text()).toContain('Sí: Persona A, Persona B');
        expect(first.find('[data-testid="best"]').exists()).toBe(true);
        expect(wrapper.get('[data-slot="d-2"]').find('[data-testid="best"]').exists()).toBe(false);
    });

    it('marks the date fixed once the survey closed instead of the best', () => {
        const wrapper = mount(ResultsSummary, {
            props: {
                results: results([
                    question({
                        type: 'availability',
                        answered: 3,
                        buckets: [],
                        availability: { ...availability, chosenSlotId: 'd-2' },
                    }),
                ]),
            },
        });

        expect(wrapper.get('[data-slot="d-2"]').find('[data-testid="chosen"]').text()).toBe(
            'Fecha elegida'
        );
        expect(wrapper.get('[data-slot="d-1"]').find('[data-testid="chosen"]').exists()).toBe(
            false
        );
    });

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

    it('lists the options of a ranking best first with their points and mean place', () => {
        const wrapper = mount(ResultsSummary, {
            props: {
                results: results([
                    question({ type: 'ranking', answered: 3, buckets: [], ranking: ranked }),
                ]),
            },
        });

        const options = wrapper.findAll('[data-ranked-option]');
        expect(options.map((option) => option.attributes('data-ranked-option'))).toEqual([
            'k-2',
            'k-1',
            'k-3',
        ]);
        expect(options[0]?.text()).toContain('Wargames');
        expect(options[0]?.text()).toContain('8 puntos · posición media 1,33');
        // Bars against the most points an option could get: first place in every answer
        expect(wrapper.findAll('[role="progressbar"]')[0]?.attributes('aria-valuenow')).toBe('89');
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
