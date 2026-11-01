import { describe, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { FormDatePicker, FormSelect, FormTimePicker } from '@/components/form';
import Respond from '../../resources/js/pages/Surveys/Respond.vue';
import type { RespondProps } from '../../resources/js/types/Respond';
import { OTHER } from '../../resources/js/types/Respond';
import { branching, everyType } from './support/definition';

function mountPage(props: Partial<RespondProps> = {}): VueWrapper {
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
        expect(wrapper.findComponent(FormSelect).exists()).toBe(true);
        expect(wrapper.findComponent(FormDatePicker).exists()).toBe(true);
        expect(wrapper.findComponent(FormTimePicker).exists()).toBe(true);
        expect(wrapper.find('select').exists()).toBe(false);
        expect(wrapper.find('input[type="date"]').exists()).toBe(false);
        expect(wrapper.findAll('[aria-labelledby$="-label"][role="radiogroup"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('Como te llamen en la mesa');
        expect(wrapper.text()).toContain('Esta encuesta es anónima');
    });

    it('sends the answers in the shape the server reads', async () => {
        const wrapper = mountPage();

        await wrapper.find('input[type="text"]').setValue('Ana');
        await wrapper.find(`input[type="radio"][value="${OTHER}"]`).setValue(true);
        await wrapper.find('input[placeholder="Escribe tu respuesta"]').setValue('Ninguna');
        await wrapper.find('input[type="checkbox"][value="o-2"]').setValue(true);
        wrapper.findComponent(FormSelect).vm.$emit('update:modelValue', 'o-1');
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
    it('asks for an email on the verified public link instead of the questions', async () => {
        const wrapper = mountPage({
            state: 'verify_email',
            definition: undefined,
            submitUrl: undefined,
            requestUrl: '/encuestas/survey-1/abierta/token/correo',
        });

        expect(wrapper.findAll('fieldset')).toHaveLength(0);
        expect(wrapper.text()).toContain('te enviaremos un enlace personal');

        await wrapper.find('input[type="email"]').setValue('ana@example.test');
        await wrapper.find('form').trigger('submit');

        expect(router.post).toHaveBeenCalledWith(
            '/encuestas/survey-1/abierta/token/correo',
            { email: 'ana@example.test' },
            expect.objectContaining({ preserveScroll: true })
        );
    });

    it('says the same once a link was asked for', () => {
        const wrapper = mountPage({
            state: 'verify_email',
            result: 'link_sent',
            definition: undefined,
            submitUrl: undefined,
            requestUrl: '/encuestas/survey-1/abierta/token/correo',
        });

        expect(wrapper.find('input[type="email"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Si la dirección es válida');
    });

    describe('with several sections', () => {
        function submitButton(wrapper: VueWrapper): string {
            return wrapper.find('button[type="submit"]').text();
        }

        it('shows one section at a time and asks for its required answers first', async () => {
            vi.mocked(router.post).mockClear();
            const wrapper = mountPage({ definition: branching });

            expect(wrapper.findAll('fieldset')).toHaveLength(1);
            expect(wrapper.text()).toContain('Sección 1');
            expect(submitButton(wrapper)).toBe('Siguiente');

            await wrapper.find('form').trigger('submit');

            expect(wrapper.findAll('fieldset')[0]?.text()).toContain(
                'Esta pregunta es obligatoria.'
            );
            expect(wrapper.text()).not.toContain('El final');
            expect(router.post).not.toHaveBeenCalled();
        });

        it('follows the jump of the chosen option and comes back the same way', async () => {
            const wrapper = mountPage({ definition: branching });

            await wrapper.find('input[type="radio"][value="o-part"]').setValue(true);
            await wrapper.find('form').trigger('submit');

            expect(wrapper.text()).toContain('Por qué lo dejaste');
            expect(wrapper.text()).toContain('Sección 2');

            await wrapper.find('form').trigger('submit');

            expect(wrapper.text()).toContain('Pregunta q-comments');
            expect(wrapper.text()).toContain('Sección 3');
            expect(submitButton(wrapper)).toBe('Enviar respuestas');

            await wrapper.find('button[type="button"]').trigger('click');

            expect(wrapper.text()).toContain('Por qué lo dejaste');
        });

        it('sends only the answers of the way taken', async () => {
            vi.mocked(router.post).mockClear();
            const wrapper = mountPage({ definition: branching });

            await wrapper.find('input[type="radio"][value="o-all"]').setValue(true);
            await wrapper.find('form').trigger('submit');
            await wrapper.find('textarea').setValue('Épico');
            await wrapper.find('button[type="button"]').trigger('click');
            await wrapper.find('input[type="radio"][value="o-none"]').setValue(true);

            expect(submitButton(wrapper)).toBe('Enviar respuestas');

            await wrapper.find('form').trigger('submit');

            expect(router.post).toHaveBeenCalledWith(
                '/encuestas/survey-1',
                { answers: { 'q-played': 'o-none' } },
                expect.objectContaining({ preserveScroll: true })
            );
        });

        it('goes back to the section of an answer the server refused', async () => {
            vi.mocked(router.post).mockImplementationOnce((_url, _data, options) => {
                options?.onError?.({ 'answers.q-played': 'Elige una de las opciones.' });
            });
            const wrapper = mountPage({ definition: branching });

            await wrapper.find('input[type="radio"][value="o-part"]').setValue(true);
            await wrapper.find('form').trigger('submit');
            await wrapper.find('form').trigger('submit');
            await wrapper.find('form').trigger('submit');

            expect(wrapper.text()).toContain('Sección 1');
            expect(wrapper.findAll('fieldset')[0]?.text()).toContain('Elige una de las opciones.');
        });
    });
});
