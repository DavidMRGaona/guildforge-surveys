import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Respond from '../../resources/js/pages/Surveys/Respond.vue';
import type { RespondProps } from '../../resources/js/types/Respond';
import { OTHER } from '../../resources/js/types/Respond';
import { everyType } from './support/definition';

function mountPage(props: Partial<RespondProps> = {}) {
    return mount(Respond, {
        props: {
            state: 'open',
            result: null,
            survey: {
                id: 'survey-1',
                kind: 'form',
                title: 'Valora la partida',
                description: null,
                anonymous: true,
                closesAt: null,
            },
            definition: everyType,
            submitUrl: '/encuestas/survey-1',
            ...props,
        },
    });
}

describe('Respond', () => {
    it('renders a field for each of the ten question types', () => {
        const wrapper = mountPage();

        expect(wrapper.findAll('fieldset')).toHaveLength(10);
        expect(wrapper.find('input[type="text"]').exists()).toBe(true);
        expect(wrapper.find('textarea').exists()).toBe(true);
        expect(wrapper.find('select').exists()).toBe(true);
        expect(wrapper.find('input[type="date"]').exists()).toBe(true);
        expect(wrapper.find('input[type="time"]').exists()).toBe(true);
        expect(wrapper.findAll('table tbody tr')).toHaveLength(2);
        expect(wrapper.text()).toContain('Como te llamen en la mesa');
        expect(wrapper.text()).toContain('Esta encuesta es anónima');
    });

    it('sends the answers in the shape the server reads', async () => {
        const wrapper = mountPage();

        await wrapper.find('input[type="text"]').setValue('Ana');
        await wrapper.find(`input[type="radio"][value="${OTHER}"]`).setValue(true);
        await wrapper.find('input[placeholder="Escribe tu respuesta"]').setValue('Ninguna');
        await wrapper.find('input[type="checkbox"][value="o-2"]').setValue(true);
        await wrapper.find('select').setValue('o-1');
        await wrapper.find('input[name="question-q-scale"][value="4"]').setValue(true);
        await wrapper.find('input[name="question-q-grid-r-1"][value="c-2"]').setValue(true);
        await wrapper.find('form').trigger('submit');

        expect(router.post).toHaveBeenCalledWith(
            '/encuestas/survey-1',
            {
                answers: {
                    'q-short': 'Ana',
                    'q-single': { option: OTHER, other: 'Ninguna' },
                    'q-multiple': ['o-2'],
                    'q-dropdown': 'o-1',
                    'q-scale': 4,
                    'q-grid': { 'r-1': 'c-2' },
                },
            },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('shows the error of a question next to it', async () => {
        vi.mocked(router.post).mockImplementationOnce((_url, _data, options) => {
            options?.onError?.({ 'answers.q-short': 'Esta pregunta es obligatoria.' });
        });
        const wrapper = mountPage();

        await wrapper.find('form').trigger('submit');

        const field = wrapper.findAll('fieldset')[0];
        expect(field?.text()).toContain('Esta pregunta es obligatoria.');
        expect(field?.attributes('aria-invalid')).toBe('true');
    });

    it('warns when the survey is not anonymous', () => {
        const wrapper = mountPage({
            survey: {
                id: 'survey-1',
                kind: 'form',
                title: 'Nominal',
                description: null,
                anonymous: false,
                closesAt: null,
            },
        });

        expect(wrapper.text()).toContain('Esta encuesta no es anónima');
    });

    it('shows a closed or answered survey without its form', () => {
        for (const state of ['closed', 'answered'] as const) {
            const wrapper = mountPage({ state, definition: undefined, submitUrl: undefined });

            expect(wrapper.find('form').exists()).toBe(false);
            expect(wrapper.find('[role="alert"]').text()).toContain(
                state === 'closed'
                    ? 'Esta encuesta está cerrada.'
                    : 'Ya has respondido esta encuesta.'
            );
        }
    });

    it('thanks after answering', () => {
        const wrapper = mountPage({ result: 'submitted' });

        expect(wrapper.find('form').exists()).toBe(false);
        expect(wrapper.find('[role="status"]').text()).toContain('¡Gracias por responder!');
    });
});
