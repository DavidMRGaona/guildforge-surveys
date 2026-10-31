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
        updateUrl: '/encuestas/sv-1/editar',
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
});
