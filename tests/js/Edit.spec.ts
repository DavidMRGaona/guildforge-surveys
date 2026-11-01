import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Edit from '../../resources/js/pages/Surveys/Manage/Edit.vue';
import type { EditSurveyProps } from '../../resources/js/types/Editor';
import type { SurveyDefinition } from '../../resources/js/types/Respond';

function definition(): SurveyDefinition {
    return {
        sections: [
            {
                id: 's-1',
                title: null,
                description: null,
                next: null,
                questions: [
                    {
                        id: 'q-1',
                        key: 'fun',
                        type: 'linear_scale',
                        title: '¿Qué tal fue?',
                        help: null,
                        required: true,
                        options: [],
                        allow_other: false,
                        scale: { min: 1, max: 5, min_label: null, max_label: null },
                        stars: null,
                        grid: null,
                        next_by_option: [],
                    },
                    {
                        id: 'q-2',
                        key: null,
                        type: 'paragraph',
                        title: 'Comentarios',
                        help: null,
                        required: false,
                        options: [],
                        allow_other: false,
                        scale: null,
                        stars: null,
                        grid: null,
                        next_by_option: [],
                    },
                ],
            },
        ],
    };
}

function props(
    overrides: Partial<EditSurveyProps> = {},
    survey: Partial<EditSurveyProps['survey']> = {}
): EditSurveyProps {
    return {
        survey: {
            id: 'sv-1',
            kind: 'form',
            title: 'Valoración',
            description: null,
            anonymous: true,
            durationDays: 7,
            closesAt: null,
            reminderHoursBefore: null,
            liveResults: false,
            visibleToRespondents: false,
            opened: false,
            pendingReview: false,
            rejectionReason: null,
            sendable: true,
            ...survey,
        },
        definition: definition(),
        subject: { title: 'Partida de ejemplo' },
        locked: false,
        endSending: null,
        submitUrl: '/encuestas/sv-1/editar',
        submitMethod: 'put',
        backUrl: '/encuestas/sujeto/example-thing/thing-1',
        ...overrides,
    };
}

describe('Edit', () => {
    it('saves the edited survey with its definition', async () => {
        vi.mocked(router.put).mockClear();
        const wrapper = mount(Edit, { props: props() });

        await wrapper.find('#survey-title').setValue('Otro título');
        await wrapper.find('#question-q-2-title').setValue('Otra pregunta');
        await wrapper.find('form').trigger('submit');

        expect(router.put).toHaveBeenCalledWith(
            '/encuestas/sv-1/editar',
            expect.objectContaining({
                title: 'Otro título',
                duration_days: 7,
                closes_at: null,
                definition: expect.objectContaining({
                    sections: [
                        expect.objectContaining({
                            questions: [
                                expect.anything(),
                                expect.objectContaining({ title: 'Otra pregunta' }),
                            ],
                        }),
                    ],
                }),
            }),
            expect.any(Object)
        );
    });

    it('says how a survey prepared for the end goes out and saves it', async () => {
        vi.mocked(router.put).mockClear();
        const wrapper = mount(Edit, {
            props: props(
                { endSending: { sendOnEnd: true, sendDelayHours: 12 } },
                { sendable: false }
            ),
        });

        expect(wrapper.text()).toContain('Al terminar');
        expect(wrapper.findAll('button').map((button) => button.text())).not.toContain(
            'Guardar y enviar'
        );

        await wrapper.find('#survey-send-delay').setValue('2');
        await wrapper.find('form').trigger('submit');

        expect(router.put).toHaveBeenCalledWith(
            '/encuestas/sv-1/editar',
            expect.objectContaining({ send_on_end: true, send_delay_hours: 2 }),
            expect.any(Object)
        );
    });

    it('hides the delay when the organizer prefers the one click', async () => {
        const wrapper = mount(Edit, {
            props: props(
                { endSending: { sendOnEnd: true, sendDelayHours: 12 } },
                { sendable: false }
            ),
        });

        await wrapper.find('#survey-send-on-end').setValue(false);

        expect(wrapper.find('#survey-send-delay').exists()).toBe(false);
    });

    it('sends nothing about the end for a survey that is not prepared', async () => {
        vi.mocked(router.put).mockClear();
        const wrapper = mount(Edit, { props: props() });

        await wrapper.find('form').trigger('submit');

        const data = vi.mocked(router.put).mock.calls[0]?.[1] as Record<string, unknown>;
        expect(data).not.toHaveProperty('send_on_end');
        expect(wrapper.text()).not.toContain('Al terminar');
    });

    it('saves and sends with one click', async () => {
        vi.mocked(router.put).mockClear();
        const wrapper = mount(Edit, { props: props() });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Guardar y enviar')
            ?.trigger('click');

        expect(vi.mocked(router.put).mock.calls[0]?.[0]).toBe('/encuestas/sv-1/editar?send=1');
    });

    it('shows each server error next to its field', async () => {
        vi.mocked(router.put).mockImplementationOnce((_url, _data, options) => {
            options?.onError?.({
                'definition.sections.0.questions.1.title': 'Escribe la pregunta.',
            });
        });
        const wrapper = mount(Edit, { props: props() });

        await wrapper.find('form').trigger('submit');

        expect(wrapper.text()).toContain('Escribe la pregunta.');
        expect(wrapper.find('#question-q-2-title').attributes('aria-invalid')).toBe('true');
    });

    it('never lets a template question change type', () => {
        const wrapper = mount(Edit, { props: props() });

        expect(wrapper.text()).toContain('Pregunta de la plantilla');
        expect(wrapper.find('#question-q-1-type').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#question-q-2-type').attributes('disabled')).toBeUndefined();
    });

    it('with responses only lets help texts and dates change', () => {
        const wrapper = mount(Edit, {
            props: props(
                { locked: true },
                { opened: true, closesAt: '2030-01-12T20:00', sendable: false }
            ),
        });

        expect(wrapper.find('#survey-title').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#question-q-2-title').attributes('disabled')).toBeDefined();
        expect(wrapper.find('#question-q-2-help').attributes('disabled')).toBeUndefined();
        expect(wrapper.find('#survey-closes-at').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('Añadir pregunta');
        expect(wrapper.text()).not.toContain('Guardar y enviar');
    });

    it('keeps the content as sent while it waits for review', () => {
        const wrapper = mount(Edit, { props: props({}, { pendingReview: true, sendable: false }) });

        expect(wrapper.text()).toContain('esperando revisión');
        expect(wrapper.find('#question-q-2-help').attributes('disabled')).toBeDefined();
    });

    it('adds a question of the chosen type', async () => {
        const wrapper = mount(Edit, { props: props() });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Añadir pregunta')
            ?.trigger('click');

        expect(wrapper.text()).toContain('Pregunta 3');
    });

    it('warns that live results weaken anonymity', async () => {
        const wrapper = mount(Edit, { props: props() });

        await wrapper.find('#survey-live').setValue(true);

        expect(wrapper.text()).toContain('debilita el anonimato');
    });

    it('creates a new poll whose title is its question', async () => {
        vi.mocked(router.post).mockClear();
        const poll: SurveyDefinition = {
            sections: [
                {
                    id: 's-1',
                    title: null,
                    description: null,
                    next: null,
                    questions: [
                        {
                            id: 'q-1',
                            key: null,
                            type: 'single_choice',
                            title: '',
                            help: null,
                            required: true,
                            options: [
                                { id: 'o-1', label: 'Viernes' },
                                { id: 'o-2', label: 'Sábado' },
                            ],
                            allow_other: false,
                            scale: null,
                            stars: null,
                            grid: null,
                            next_by_option: [],
                        },
                    ],
                },
            ],
        };
        const wrapper = mount(Edit, {
            props: props(
                {
                    definition: poll,
                    submitUrl: '/encuestas/sujeto/example-thing/thing-1/nueva/sondeo',
                    submitMethod: 'post',
                },
                { id: null, kind: 'poll', title: '' }
            ),
        });

        expect(wrapper.find('h1').text()).toBe('Nuevo sondeo');
        expect(wrapper.find('#survey-title').exists()).toBe(false);
        expect(wrapper.text()).toContain('Guardar borrador');

        await wrapper.find('#question-q-1-title').setValue('¿Qué día jugamos?');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Guardar y enviar')
            ?.trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/encuestas/sujeto/example-thing/thing-1/nueva/sondeo?send=1',
            expect.objectContaining({ title: '¿Qué día jugamos?' }),
            expect.any(Object)
        );
    });

    it('keeps the title field of a new form', () => {
        const wrapper = mount(Edit, {
            props: props({ submitMethod: 'post' }, { id: null, title: '' }),
        });

        expect(wrapper.find('h1').text()).toBe('Nueva encuesta');
        expect(wrapper.find('#survey-title').exists()).toBe(true);
    });
});
