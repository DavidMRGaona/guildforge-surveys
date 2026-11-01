import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { FormCombobox, FormDatePicker, FormSelect, FormTimePicker } from '@/components/form';
import QuestionField from '../../resources/js/components/QuestionField.vue';
import {
    OTHER,
    type AnswerDraft,
    type SurveyQuestion,
    type SurveySlot,
} from '../../resources/js/types/Respond';
import { emptyDraft } from '../../resources/js/utils/answers';

function question(
    type: SurveyQuestion['type'],
    extra: Partial<SurveyQuestion> = {}
): SurveyQuestion {
    return {
        id: 'q-1',
        key: null,
        type,
        title: '¿Qué te pareció?',
        help: null,
        required: false,
        options: [
            { id: 'o-1', label: 'Uno' },
            { id: 'o-2', label: 'Dos' },
        ],
        allow_other: false,
        scale: null,
        stars: null,
        grid: null,
        next_by_option: [],
        slots: [],
        ...extra,
    };
}

function mountField(
    asked: SurveyQuestion,
    draft: Partial<AnswerDraft> = {},
    error?: string
): VueWrapper {
    const wrapper: VueWrapper = mount(QuestionField, {
        props: {
            question: asked,
            modelValue: { ...emptyDraft(), ...draft },
            'onUpdate:modelValue': (value: AnswerDraft) => wrapper.setProps({ modelValue: value }),
            ...(error === undefined ? {} : { error }),
        },
        attachTo: document.body,
    });

    return wrapper;
}

function draftOf(wrapper: VueWrapper): AnswerDraft {
    return (wrapper.props() as unknown as { modelValue: AnswerDraft }).modelValue;
}

describe('QuestionField', () => {
    it('marks each proposed date yes, if needed or no', async () => {
        const slot = (id: string, label: string): SurveySlot => ({
            id,
            date: null,
            starts_at: null,
            ends_at: null,
            day: '2030-01-12',
            start: null,
            end: null,
            label,
        });
        const wrapper = mountField(
            question('availability', {
                required: true,
                options: [],
                slots: [
                    slot('d-1', 'sábado, 12 de enero de 2030'),
                    slot('d-2', 'domingo, 13 de enero de 2030'),
                ],
            })
        );

        expect(wrapper.text()).toContain('Las que dejes sin marcar cuentan como «No».');
        // Always answered, so never marked as required
        expect(wrapper.text()).not.toContain('Obligatoria');
        expect(wrapper.findAll('[role="radiogroup"]')).toHaveLength(2);
        await wrapper.find('input[name="question-q-1-d-1"][value="yes"]').setValue(true);
        await wrapper.find('input[name="question-q-1-d-2"][value="if_needed"]').setValue(true);

        expect(draftOf(wrapper).cells).toEqual({ 'd-1': 'yes', 'd-2': 'if_needed' });
        expect(wrapper.find('[data-answer="yes"]').classes()).toContain('bg-success-bg');
    });

    it('keeps the order of a ranking in the choices, best first', async () => {
        const wrapper = mountField(question('ranking'));

        await wrapper.find('[data-unplaced="o-2"]').trigger('click');

        // Two options: placing one places the other
        expect(draftOf(wrapper).choices).toEqual(['o-2', 'o-1']);
        expect(wrapper.findAll('[data-ranked]').map((item) => item.text())).toEqual([
            expect.stringContaining('Dos'),
            expect.stringContaining('Uno'),
        ]);
    });

    it('shows single choices as cards that mark the chosen one', async () => {
        const wrapper = mountField(question('single_choice'));

        await wrapper.find('input[type="radio"][value="o-2"]').setValue(true);

        expect(draftOf(wrapper).choice).toBe('o-2');
        const cards = wrapper.findAll('[data-option]');
        expect(cards).toHaveLength(2);
        expect(cards[1]?.attributes('data-chosen')).toBe('true');
        expect(cards[0]?.attributes('data-chosen')).toBeUndefined();
    });

    it('asks for the text of "Other" only once it is chosen', async () => {
        const wrapper = mountField(question('single_choice', { allow_other: true }));

        expect(wrapper.find('input[placeholder="Escribe tu respuesta"]').exists()).toBe(false);

        await wrapper.find(`input[type="radio"][value="${OTHER}"]`).setValue(true);
        await wrapper.find('input[placeholder="Escribe tu respuesta"]').setValue('Ninguna');

        expect(draftOf(wrapper)).toMatchObject({ choice: OTHER, other: 'Ninguna' });
    });

    it('lets several choices be ticked and unticked', async () => {
        const wrapper = mountField(question('multiple_choice'));

        await wrapper.find('input[type="checkbox"][value="o-1"]').setValue(true);
        await wrapper.find('input[type="checkbox"][value="o-2"]').setValue(true);
        await wrapper.find('input[type="checkbox"][value="o-1"]').setValue(false);

        expect(draftOf(wrapper).choices).toEqual(['o-2']);
    });

    it('uses the site select for a short dropdown and a searchable one for a long list', async () => {
        const short = mountField(question('dropdown', { allow_other: true }));
        const select = short.findComponent(FormSelect);

        expect(select.props('options')).toEqual([
            { value: 'o-1', label: 'Uno' },
            { value: 'o-2', label: 'Dos' },
            { value: OTHER, label: 'Otra' },
        ]);
        select.vm.$emit('update:modelValue', 'o-2');
        await short.vm.$nextTick();
        expect(draftOf(short).choice).toBe('o-2');

        const options = Array.from({ length: 12 }, (_, index) => ({
            id: `o-${index}`,
            label: `Opción ${index}`,
        }));
        const long = mountField(question('dropdown', { options }));

        expect(long.findComponent(FormSelect).exists()).toBe(false);
        expect(long.findComponent(FormCombobox).exists()).toBe(true);
    });

    it('shows a scale as a row of buttons with the meaning of its ends', async () => {
        const wrapper = mountField(
            question('linear_scale', {
                scale: { min: 0, max: 10, min_label: 'Nada', max_label: 'Mucho' },
            })
        );

        expect(wrapper.find('[role="radiogroup"]').exists()).toBe(true);
        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(11);
        expect(wrapper.text()).toContain('0: Nada');
        expect(wrapper.text()).toContain('10: Mucho');

        await wrapper.find('input[type="radio"][value="7"]').setValue(true);

        expect(draftOf(wrapper).number).toBe(7);
    });

    it('rates with stars named for screen readers', async () => {
        const wrapper = mountField(question('star_rating', { stars: 5 }));

        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(5);
        expect(wrapper.text()).toContain('3 de 5 estrellas');

        await wrapper.find('input[type="radio"][value="3"]').setValue(true);

        expect(draftOf(wrapper).number).toBe(3);
    });

    it('picks dates and times with the site pickers', async () => {
        const date = mountField(question('date'));
        const picker = date.findComponent(FormDatePicker);

        expect(picker.exists()).toBe(true);
        expect(date.find('input').attributes('aria-label')).toBe('¿Qué te pareció?');

        await date.find('input').setValue('7/3/2026');
        expect(draftOf(date).text).toBe('2026-03-07');

        const time = mountField(question('time'));
        expect(time.findComponent(FormTimePicker).exists()).toBe(true);

        await time.find('input').setValue('9h30');
        expect(draftOf(time).text).toBe('09:30');
    });

    it('answers a grid row by row, each row a group of its own', async () => {
        const wrapper = mountField(
            question('grid', {
                grid: {
                    rows: [
                        { id: 'r-1', label: 'Ambiente' },
                        { id: 'r-2', label: 'Ritmo' },
                    ],
                    columns: [
                        { id: 'c-1', label: 'Mal' },
                        { id: 'c-2', label: 'Bien' },
                    ],
                },
            })
        );

        const rows = wrapper.findAll('[role="radiogroup"]');
        expect(rows).toHaveLength(2);
        expect(wrapper.find('table').exists()).toBe(false);

        await wrapper.find('input[name="question-q-1-r-2"][value="c-1"]').setValue(true);

        expect(draftOf(wrapper).cells).toEqual({ 'r-2': 'c-1' });
        expect(rows[1]?.text()).toContain('Mal');
    });

    it('marks the whole question when it has an error', () => {
        const wrapper = mountField(
            question('short_text', { required: true }),
            {},
            'Esta pregunta es obligatoria.'
        );

        const field = wrapper.get('fieldset');
        expect(field.attributes('aria-invalid')).toBe('true');
        expect(field.text()).toContain('Esta pregunta es obligatoria.');
        expect(field.text()).toContain('Obligatoria');
    });
});
