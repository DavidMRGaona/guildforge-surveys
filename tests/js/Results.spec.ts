import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import type { VNode } from 'vue';
import { router } from '@inertiajs/vue3';
import Results from '../../resources/js/pages/Surveys/Manage/Results.vue';
import type { ManageResultsProps, OrganizerQuestionResult } from '../../resources/js/types/Results';

vi.mock('../../resources/js/components/results/QuestionChart.vue', async () => {
    const { defineComponent, h } = await import('vue');

    return {
        default: defineComponent({
            name: 'QuestionChart',
            props: {
                question: { type: Object, required: true },
                chartType: { type: String, required: true },
            },
            setup(props) {
                return (): VNode => h('div', { 'data-chart': props.chartType });
            },
        }),
    };
});

function question(
    id: string,
    overrides: Partial<OrganizerQuestionResult> = {}
): OrganizerQuestionResult {
    return {
        id,
        title: `Pregunta ${id}`,
        type: 'single_choice',
        answered: 2,
        belowMinimum: false,
        buckets: [],
        grid: null,
        mean: null,
        median: null,
        ranking: null,
        availability: null,
        reached: null,
        chartType: 'bar',
        chartTypes: ['bar', 'pie'],
        texts: [],
        ...overrides,
    };
}

function props(overrides: Partial<ManageResultsProps> = {}): ManageResultsProps {
    return {
        survey: { id: 's-1', title: 'Valora la partida', anonymous: false, closed: false },
        subject: { title: 'Partida de ejemplo' },
        results: {
            visible: true,
            hiddenReason: null,
            responses: 3,
            audienceSize: 4,
            rate: 0.75,
            questions: [
                question('q-1'),
                question('q-2', { type: 'paragraph', chartType: 'list', chartTypes: ['list'] }),
            ],
        },
        hiddenQuestions: [{ id: 'q-3', title: 'Comentarios privados' }],
        settingsUrl: '/encuestas/gestionar/s-1/resultados',
        chooseDateUrl: null,
        backUrl: '/encuestas/de/thing/thing-1',
        ...overrides,
    };
}

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

describe('Results', () => {
    it('fixes the date of a closed availability survey once the consequence is ticked', async () => {
        vi.mocked(router.post).mockClear();
        const wrapper = mount(Results, {
            props: props({
                survey: { id: 's-1', title: '¿Qué día?', anonymous: false, closed: true },
                results: {
                    visible: true,
                    hiddenReason: null,
                    responses: 3,
                    audienceSize: 4,
                    rate: 0.75,
                    questions: [
                        question('q-1', {
                            type: 'availability',
                            availability,
                            chartType: 'stacked_bar',
                            chartTypes: ['stacked_bar'],
                        }),
                    ],
                },
                chooseDateUrl: '/encuestas/gestionar/s-1/fecha',
            }),
        });

        // The slots instead of a chart
        expect(wrapper.findAll('[data-chart]')).toHaveLength(0);
        expect(wrapper.find('[data-slot="d-1"]').exists()).toBe(true);
        const card = wrapper.get('[data-testid="choose-date"]');
        const button = card.findAll('button').find((found) => found.text() === 'Fijar y avisar');
        expect(button?.attributes('disabled')).toBeDefined();

        await card.get('#choose-date-confirm').setValue(true);
        await button?.trigger('click');

        // The best date comes chosen
        expect(router.post).toHaveBeenCalledWith(
            '/encuestas/gestionar/s-1/fecha',
            { slot_id: 'd-1', confirmed: true },
            expect.any(Object)
        );
    });

    it('offers no date to fix without the address to fix it', () => {
        const wrapper = mount(Results, { props: props() });

        expect(wrapper.find('[data-testid="choose-date"]').exists()).toBe(false);
    });

    it('shows the participation and a chart per question', () => {
        const wrapper = mount(Results, { props: props() });

        expect(wrapper.get('[data-testid="responses"]').text()).toBe('3 de 4');
        expect(wrapper.get('[data-testid="rate"]').text()).toContain('75');
        expect(wrapper.findAll('[data-chart]')).toHaveLength(2);
        // Only questions with more than one chart offer the choice
        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(2);
    });

    it('says how many reached a question some sections skipped', () => {
        const wrapper = mount(Results, {
            props: props({
                results: {
                    ...props().results,
                    questions: [question('q-1'), question('q-2', { reached: 2 })],
                },
            }),
        });

        expect(wrapper.text()).toContain('La vieron 2 de 3');
        expect(wrapper.text().match(/La vieron/g)).toHaveLength(1);
    });

    it('offers the chart types as one segmented choice, still radio buttons underneath', () => {
        const wrapper = mount(Results, { props: props() });

        const group = wrapper.get('[data-question="q-1"] fieldset');
        const radios = group.findAll('input[type="radio"]');
        expect(radios).toHaveLength(2);
        for (const radio of radios) {
            expect(radio.classes()).toContain('sr-only');
            // The chosen segment inverts the theme colours: a lighter grey would not tell it apart
            expect(
                radio.element.nextElementSibling?.classList.contains('peer-checked:bg-base-primary')
            ).toBe(true);
        }
    });

    it('marks a question to leave out without fading its text', async () => {
        const wrapper = mount(Results, { props: props() });

        await wrapper.get('#hide-q-1').setValue(true);

        const article = wrapper.get('[data-question="q-1"]');
        expect(article.classes()).not.toContain('opacity-60');
        expect(article.get('[data-testid="left-out"]').text()).not.toBe('');
    });

    it('leads with the way back to the surveys, the subject and the title', () => {
        const wrapper = mount(Results, { props: props() });

        const header = wrapper.get('header');
        expect(header.find('a').attributes('href')).toBe('/encuestas/de/thing/thing-1');
        expect(header.text()).toContain('Partida de ejemplo');
        expect(header.get('h1').text()).toContain('Valora la partida');
    });

    it('saves the chosen charts and the questions left out', async () => {
        const wrapper = mount(Results, { props: props() });

        await wrapper.get('input[name="chart-q-1"][value="pie"]').setValue(true);
        expect(wrapper.get('[data-question="q-1"] [data-chart]').attributes('data-chart')).toBe(
            'pie'
        );

        await wrapper.get('#hide-q-2').trigger('change');
        await wrapper.get('#hidden-q-3').trigger('change');
        await wrapper
            .findAll('button')
            .find((button) => button.text().startsWith('Guardar'))
            ?.trigger('click');

        expect(router.put).toHaveBeenCalledWith(
            '/encuestas/gestionar/s-1/resultados',
            { chart_types: { 'q-1': 'pie', 'q-2': 'list' }, hidden_question_ids: ['q-2'] },
            expect.any(Object)
        );
    });

    it('explains why an anonymous survey shows nothing yet', () => {
        const wrapper = mount(Results, {
            props: props({
                survey: { id: 's-1', title: 'Valora la partida', anonymous: true, closed: false },
                results: {
                    visible: false,
                    hiddenReason: 'not_closed',
                    responses: 3,
                    audienceSize: 4,
                    rate: 0.75,
                    questions: [],
                },
            }),
        });

        expect(wrapper.get('[data-testid="hidden-notice"]').text()).toContain('cuando cierre');
        expect(wrapper.find('[data-chart]').exists()).toBe(false);
    });
});
