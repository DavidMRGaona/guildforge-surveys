import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import Subject from '../../resources/js/pages/Surveys/Manage/Subject.vue';
import type { ManageSubjectProps } from '../../resources/js/types/Manage';

function props(overrides: Partial<ManageSubjectProps> = {}): ManageSubjectProps {
    return {
        subject: {
            title: 'Partida de ejemplo',
            typeLabel: 'Mesa',
            url: '/mesas/partida-de-ejemplo',
            status: 'active',
        },
        surveys: [],
        prepareUrl: null,
        ...overrides,
    };
}

describe('Subject', () => {
    it('prepares the survey before the subject ends', async () => {
        vi.mocked(router.post).mockClear();
        const wrapper = mount(Subject, {
            props: props({ prepareUrl: '/encuestas/sujeto/example-thing/thing-1/preparar' }),
        });

        expect(wrapper.text()).toContain('Prepara la encuesta');
        expect(wrapper.text()).toContain('elegir si se envía sola al terminar');
        expect(wrapper.text()).not.toContain('Aún no hay encuestas');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Preparar encuesta')
            ?.trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/encuestas/sujeto/example-thing/thing-1/preparar',
            {},
            expect.any(Object)
        );
    });

    it('says a subject that already ended can be prepared and sent', () => {
        const wrapper = mount(Subject, {
            props: props({
                subject: { ...props().subject, status: 'ended' },
                prepareUrl: '/encuestas/sujeto/example-thing/thing-1/preparar',
            }),
        });

        expect(wrapper.text()).toContain('Ya ha terminado');
    });

    it('tells a cancelled subject will have no survey', () => {
        const wrapper = mount(Subject, {
            props: props({ subject: { ...props().subject, status: 'cancelled' } }),
        });

        expect(wrapper.text()).toContain('Se ha cancelado');
        expect(wrapper.text()).not.toContain('Preparar encuesta');
    });

    it('shows the empty state when there is nothing to prepare', () => {
        const wrapper = mount(Subject, { props: props() });

        expect(wrapper.text()).toContain('Aún no hay encuestas');
    });
});
