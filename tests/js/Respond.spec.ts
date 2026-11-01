import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
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
            draftScope: 'scope-a',
            ...props,
        },
    });
}

describe('Respond', () => {
    beforeEach(() => {
        sessionStorage.clear();
    });

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
            expect(wrapper.text()).toContain('Paso 1 de 3');
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
            expect(wrapper.text()).toContain('Paso 2 de 3');

            await wrapper.find('form').trigger('submit');

            expect(wrapper.text()).toContain('Pregunta q-comments');
            expect(wrapper.text()).toContain('Paso 3 de 3');
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

            expect(wrapper.text()).toContain('Paso 1 de 3');
            expect(wrapper.findAll('fieldset')[0]?.text()).toContain('Elige una de las opciones.');
        });
        it('counts the steps of the way the answers take', async () => {
            const wrapper = mountPage({ definition: branching });

            await wrapper.find('input[type="radio"][value="o-none"]').setValue(true);

            expect(wrapper.text()).toContain('Paso 1 de 1');
            expect(wrapper.get('[role="progressbar"]').attributes('aria-valuemax')).toBe('1');
            expect(submitButton(wrapper)).toBe('Enviar respuestas');
        });

        it('resumes on the same step after a reload, and goes back the same way', async () => {
            const first = mountPage({ definition: branching });
            await first.find('input[type="radio"][value="o-part"]').setValue(true);
            await first.find('form').trigger('submit');
            await first.find('input[type="text"]').setValue('Me tuve que ir');
            first.unmount();

            const wrapper = mountPage({ definition: branching });
            await flushPromises();

            expect(wrapper.text()).toContain('Por qué lo dejaste');
            expect(wrapper.text()).toContain('Paso 2 de 3');
            expect((wrapper.find('input[type="text"]').element as HTMLInputElement).value).toBe(
                'Me tuve que ir'
            );

            await wrapper.find('button[type="button"]').trigger('click');

            expect(
                (wrapper.find('input[type="radio"][value="o-part"]').element as HTMLInputElement)
                    .checked
            ).toBe(true);
        });
    });

    it('forgets the draft once the answers are in', async () => {
        const wrapper = mountPage();
        await wrapper.find('input[type="text"]').setValue('Ana');
        expect(sessionStorage.length).toBe(1);
        wrapper.unmount();

        mountPage({ result: 'submitted', draftScope: undefined });

        expect(sessionStorage.length).toBe(0);
    });

    it('never offers a draft kept for someone else in the same tab', async () => {
        const first = mountPage();
        await first.find('input[type="text"]').setValue('Ana');
        first.unmount();

        const wrapper = mountPage({ draftScope: 'scope-b' });

        expect((wrapper.find('input[type="text"]').element as HTMLInputElement).value).toBe('');
        expect(Object.keys(sessionStorage).some((key) => key.endsWith('scope-a'))).toBe(false);
    });

    it('says what the survey is about, until when, and whether it is anonymous', () => {
        const wrapper = mountPage({
            subject: {
                typeLabel: 'Mesa de juego',
                title: 'La maldición de Strahd',
                startsAt: '2030-01-05T16:00:00+00:00',
                url: '/mesas/strahd',
            },
            survey: {
                id: 'survey-1',
                kind: 'form',
                title: 'Valora la partida',
                description: null,
                anonymous: true,
                closesAt: '2030-01-12T22:00:00+00:00',
            },
        });

        const header = wrapper.get('header');
        expect(header.text()).toContain('Mesa de juego');
        expect(header.get('a[href="/mesas/strahd"]').text()).toContain('La maldición de Strahd');
        expect(header.text()).toContain('Abierta hasta el');
        expect(header.text()).toContain('Encuesta anónima');
    });

    it('thanks and leads back to the subject and to the pending surveys', () => {
        const wrapper = mountPage({
            result: 'submitted',
            subject: {
                typeLabel: 'Mesa de juego',
                title: 'La maldición de Strahd',
                startsAt: null,
                url: '/mesas/strahd',
            },
            profileUrl: '/perfil?tab=surveys',
        });

        const thanks = wrapper.get('[role="status"]');
        expect(thanks.text()).toContain('¡Gracias por responder!');
        expect(thanks.text()).toContain('sin tu nombre');
        expect(wrapper.get('nav a[href="/mesas/strahd"]').text()).toBe(
            'Volver a La maldición de Strahd'
        );
        expect(wrapper.get('a[href="/perfil?tab=surveys"]').text()).toBe(
            'Ver mis encuestas pendientes'
        );
        expect(wrapper.find('a[href="/"]').exists()).toBe(false);
    });

    it('thanks a guest with a way home', () => {
        const wrapper = mountPage({ result: 'submitted' });

        expect(wrapper.get('a[href="/"]').text()).toBe('Volver al inicio');
    });
});
