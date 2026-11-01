import { describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import { mount, type VueWrapper } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import { FormSelect } from '@/components/form';
import BaseButton from '@/components/ui/BaseButton.vue';
import Edit from '../../resources/js/pages/Surveys/Manage/Edit.vue';
import type { EditSurveyProps } from '../../resources/js/types/Editor';
import {
    SUBMIT,
    type SurveyDefinition,
    type SurveyQuestion,
} from '../../resources/js/types/Respond';

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

/** Three sections: a single choice and a checkbox question first, then two more sections. */
function jumping(firstQuestion: Partial<SurveyQuestion> = {}): SurveyDefinition {
    const question = (
        id: string,
        type: SurveyQuestion['type'],
        extra: Partial<SurveyQuestion> = {}
    ): SurveyQuestion => ({
        id,
        key: null,
        type,
        title: `Pregunta ${id}`,
        help: null,
        required: false,
        options: [],
        allow_other: false,
        scale: null,
        stars: null,
        grid: null,
        next_by_option: [],
        ...extra,
    });
    const options = [
        { id: 'o-1', label: 'Sí' },
        { id: 'o-2', label: 'No' },
    ];

    return {
        sections: [
            {
                id: 's-1',
                title: null,
                description: null,
                next: null,
                questions: [
                    question('q-1', 'single_choice', { options, ...firstQuestion }),
                    question('q-2', 'multiple_choice', { options }),
                ],
            },
            {
                id: 's-2',
                title: 'Detalles',
                description: null,
                next: null,
                questions: [question('q-3', 'paragraph')],
            },
            {
                id: 's-3',
                title: null,
                description: null,
                next: null,
                questions: [question('q-4', 'paragraph')],
            },
        ],
    };
}

function select(
    wrapper: VueWrapper,
    id: string
): VueWrapper<InstanceType<typeof FormSelect>> | undefined {
    return wrapper.findAllComponents(FormSelect).find((field) => field.props('id') === id);
}

function sentDefinition(): SurveyDefinition {
    // The payload the page sent, read back as the page builds it
    const data = vi.mocked(router.put).mock.calls[0]?.[1] as unknown as {
        definition: SurveyDefinition;
    };

    return data.definition;
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

    it('writes the anonymity warning in the theme text over the warning colour', async () => {
        const wrapper = mount(Edit, { props: props() });

        await wrapper.find('#survey-live').setValue(true);

        const warning = wrapper.get('[data-testid="live-results-warning"]');
        expect(warning.text()).toContain('debilita el anonimato');
        expect(warning.classes()).toContain('bg-warning-light');
        expect(warning.classes()).toContain('text-base-primary');
    });

    it('writes the review and rejection notices in the theme text', () => {
        const wrapper = mount(Edit, {
            props: props({}, { pendingReview: true, rejectionReason: 'Falta el sistema' }),
        });

        const notices = wrapper.findAll('[role="status"]');
        expect(notices.length).toBeGreaterThanOrEqual(2);
        for (const notice of notices) {
            expect(notice.classes()).toContain('text-base-primary');
        }
    });

    it('leads with the way back to the subject surveys and the page title', () => {
        const wrapper = mount(Edit, { props: props() });

        const header = wrapper.get('header');
        expect(header.find('a').attributes('href')).toBe('/encuestas/sujeto/example-thing/thing-1');
        expect(header.text()).toContain('Partida de ejemplo');
        expect(header.get('h1').text()).toBe('Editar la encuesta');
    });

    it('keeps the save buttons within reach at the bottom of a phone screen', () => {
        const wrapper = mount(Edit, { props: props() });

        const actions = wrapper.get('[data-testid="editor-actions"]');
        expect(actions.classes()).toContain('sticky');
        expect(actions.classes()).toContain('sm:static');
        expect(actions.text()).toContain('Guardar y enviar');
    });

    it('puts each section title next to its move and remove buttons, big enough to tap', () => {
        const wrapper = mount(Edit, { props: props({ definition: jumping() }) });

        const heading = wrapper.findAll('h3').find((h) => h.text() === 'Sección 2');
        const row = heading?.element.parentElement;
        const remove = row?.querySelector('button[aria-label="Quitar la sección"]');
        expect(remove).not.toBeNull();
        expect(remove?.classList.contains('h-11')).toBe(true);
    });

    it('names each option field with its number', () => {
        const wrapper = mount(Edit, { props: props({ definition: jumping() }) });

        const labels = wrapper
            .findAll('#question-q-1-options input[type="text"], fieldset input[type="text"]')
            .map((input) => input.attributes('aria-label'))
            .filter((label): label is string => label !== undefined && label.startsWith('Opción'));
        expect(labels.slice(0, 2)).toEqual(['Opción 1', 'Opción 2']);
    });

    it('makes the save button the main one when it is the only action', () => {
        const wrapper = mount(Edit, { props: props({}, { sendable: false }) });

        const save = wrapper
            .findAllComponents(BaseButton)
            .find((button) => button.text() === 'Guardar');
        expect(save?.props('variant')).toBe('primary');
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

    describe('section jumps', () => {
        it('offers them on single choice and dropdown questions, to later sections and the end', async () => {
            const wrapper = mount(Edit, { props: props({ definition: jumping() }) });

            expect(wrapper.find('#question-q-1-branches').exists()).toBe(true);
            expect(wrapper.find('#question-q-2-branches').exists()).toBe(false);
            expect(select(wrapper, 'question-q-1-jump-o-1')).toBeUndefined();
            expect(select(wrapper, 'section-s-1-next')?.props('options')).toEqual([
                { value: '', label: 'Seguir con la sección siguiente' },
                { value: 's-2', label: 'Detalles' },
                { value: 's-3', label: 'Sección 3' },
                { value: SUBMIT, label: 'Enviar el formulario' },
            ]);

            await wrapper.find('#question-q-1-branches').trigger('change');

            expect(select(wrapper, 'question-q-1-jump-o-1')?.props('options')).toEqual([
                { value: '', label: 'Lo que diga la sección' },
                { value: 's-2', label: 'Detalles' },
                { value: 's-3', label: 'Sección 3' },
                { value: SUBMIT, label: 'Enviar el formulario' },
            ]);
        });

        it('saves the jumps chosen', async () => {
            vi.mocked(router.put).mockClear();
            const wrapper = mount(Edit, { props: props({ definition: jumping() }) });

            await wrapper.find('#question-q-1-branches').trigger('change');
            select(wrapper, 'question-q-1-jump-o-2')?.vm.$emit('update:modelValue', 's-3');
            await nextTick();
            select(wrapper, 'section-s-2-next')?.vm.$emit('update:modelValue', SUBMIT);
            await nextTick();
            await wrapper.find('form').trigger('submit');

            const sent = sentDefinition();
            expect(sent.sections[0]?.questions[0]?.next_by_option).toEqual([
                { option_id: 'o-2', next: 's-3' },
            ]);
            expect(sent.sections[1]?.next).toBe(SUBMIT);
        });

        it('switching them off drops them', async () => {
            vi.mocked(router.put).mockClear();
            const wrapper = mount(Edit, {
                props: props({
                    definition: jumping({ next_by_option: [{ option_id: 'o-1', next: SUBMIT }] }),
                }),
            });

            expect(select(wrapper, 'question-q-1-jump-o-1')?.props('modelValue')).toBe(SUBMIT);

            await wrapper.find('#question-q-1-branches').trigger('change');
            await wrapper.find('form').trigger('submit');

            expect(sentDefinition().sections[0]?.questions[0]?.next_by_option).toEqual([]);
        });

        it('removing a section clears the jumps that led to it', async () => {
            vi.mocked(router.put).mockClear();
            const wrapper = mount(Edit, {
                props: props({
                    definition: jumping({ next_by_option: [{ option_id: 'o-2', next: 's-3' }] }),
                }),
            });

            await wrapper.findAll('button[aria-label="Quitar la sección"]')[2]?.trigger('click');
            await wrapper.find('form').trigger('submit');

            expect(sentDefinition().sections).toHaveLength(2);
            expect(sentDefinition().sections[0]?.questions[0]?.next_by_option).toEqual([]);
        });

        it('shows each jump error next to its select', async () => {
            vi.mocked(router.put).mockImplementationOnce((_url, _data, options) => {
                options?.onError?.({
                    'definition.sections.0.questions.0.next_by_option.0.next':
                        'Solo se puede ir a una sección posterior o enviar el formulario.',
                    'definition.sections.1.next': 'La sección de destino ya no existe: elige otra.',
                });
            });
            const wrapper = mount(Edit, {
                props: props({
                    definition: jumping({ next_by_option: [{ option_id: 'o-2', next: 's-1' }] }),
                }),
            });

            await wrapper.find('form').trigger('submit');

            expect(select(wrapper, 'question-q-1-jump-o-2')?.props('error')).toBe(
                'Solo se puede ir a una sección posterior o enviar el formulario.'
            );
            expect(select(wrapper, 'section-s-2-next')?.props('error')).toBe(
                'La sección de destino ya no existe: elige otra.'
            );
        });

        it('are locked with responses and absent from polls', () => {
            const locked = mount(Edit, { props: props({ definition: jumping(), locked: true }) });

            expect(select(locked, 'section-s-1-next')?.props('disabled')).toBe(true);
            expect(locked.find('#question-q-1-branches').attributes('disabled')).toBeDefined();

            const poll = mount(Edit, { props: props({}, { kind: 'poll' }) });

            expect(poll.text()).not.toContain('Después de esta sección');
        });
    });
});
