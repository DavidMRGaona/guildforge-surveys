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
        createUrls: null,
        endChoice: null,
        ...overrides,
    };
}

function endChoice(
    overrides: Partial<NonNullable<ManageSubjectProps['endChoice']>> = {}
): NonNullable<ManageSubjectProps['endChoice']> {
    return {
        options: [
            { value: 'type-default', label: 'Plantilla del tipo: «Valoración»' },
            { value: 'template:2', label: 'Torneo de ejemplo' },
            { value: 'own', label: 'Una encuesta propia' },
            { value: 'none', label: 'Ninguna' },
        ],
        selected: 'type-default',
        ownValue: 'own',
        baseTemplates: [
            { value: 'template:1', label: 'Valoración' },
            { value: 'template:2', label: 'Torneo de ejemplo' },
        ],
        locked: false,
        lockedReason: null,
        editUrl: null,
        url: '/encuestas/sujeto/example-thing/thing-1/encuesta-al-terminar',
        ...overrides,
    };
}

describe('Subject', () => {
    it('saves the end survey chosen for the subject', async () => {
        vi.mocked(router.post).mockClear();
        const wrapper = mount(Subject, { props: props({ endChoice: endChoice() }) });

        expect(wrapper.text()).toContain('Encuesta al terminar');
        expect(wrapper.text()).toContain('Plantilla del tipo: «Valoración»');
        expect(wrapper.text()).not.toContain('Partir de');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Guardar')
            ?.trigger('click');

        expect(router.post).toHaveBeenCalledWith(
            '/encuestas/sujeto/example-thing/thing-1/encuesta-al-terminar',
            { choice: 'type-default', base: null },
            expect.any(Object)
        );
    });

    it('asks which template an own survey starts from', () => {
        const wrapper = mount(Subject, {
            props: props({ endChoice: endChoice({ selected: 'own' }) }),
        });

        expect(wrapper.text()).toContain('Partir de');
    });

    it('shows a locked choice with its reason and no save button', () => {
        const wrapper = mount(Subject, {
            props: props({
                endChoice: endChoice({
                    locked: true,
                    lockedReason:
                        'Ya ha terminado, así que la encuesta al terminar no se puede cambiar.',
                    editUrl: '/encuestas/1/editar',
                }),
            }),
        });

        expect(wrapper.text()).toContain('Ya ha terminado, así que');
        expect(wrapper.findAll('button').some((button) => button.text() === 'Guardar')).toBe(false);
    });

    it('links to the end survey in force', () => {
        const wrapper = mount(Subject, {
            props: props({ endChoice: endChoice({ editUrl: '/encuestas/1/editar' }) }),
        });

        expect(wrapper.find('a[href="/encuestas/1/editar"]').text()).toBe('Editar la encuesta');
    });

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
        // Nor the promise of a survey when it ends
        expect(wrapper.text()).not.toContain('Aún no hay encuestas');
    });

    it('leads with the way back to the subject, its kind and the page title', () => {
        const wrapper = mount(Subject, { props: props() });

        const header = wrapper.get('header');
        expect(header.find('a').attributes('href')).toBe('/mesas/partida-de-ejemplo');
        expect(header.text()).toContain('Mesa');
        expect(header.get('h1').text()).toBe('Encuestas de Partida de ejemplo');
    });

    it('shows the empty state when there is nothing to prepare', () => {
        const wrapper = mount(Subject, { props: props() });

        expect(wrapper.text()).toContain('Aún no hay encuestas');
    });

    it('offers a new survey and a new poll while the subject takes them', () => {
        const wrapper = mount(Subject, {
            props: props({
                createUrls: {
                    form: '/encuestas/sujeto/example-thing/thing-1/nueva/encuesta',
                    poll: '/encuestas/sujeto/example-thing/thing-1/nueva/sondeo',
                },
            }),
        });

        expect(wrapper.text()).toContain('Pregunta a tu gente');
        const links = Object.fromEntries(
            wrapper.findAll('a').map((link) => [link.text(), link.attributes('href')])
        );
        expect(links['Nueva encuesta']).toBe(
            '/encuestas/sujeto/example-thing/thing-1/nueva/encuesta'
        );
        expect(links['Nuevo sondeo']).toBe('/encuestas/sujeto/example-thing/thing-1/nueva/sondeo');
    });

    it('offers nothing new without create links', () => {
        const wrapper = mount(Subject, { props: props() });

        expect(wrapper.text()).not.toContain('Pregunta a tu gente');
    });
});
