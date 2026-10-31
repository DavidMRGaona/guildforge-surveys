import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { router } from '@inertiajs/vue3';
import OrganizedSurveysList from '../../resources/js/components/OrganizedSurveysList.vue';
import ProfilePendingSurveysSection from '../../resources/js/components/profile/ProfilePendingSurveysSection.vue';
import type { OrganizedSurvey } from '../../resources/js/types/Manage';

function survey(overrides: Partial<OrganizedSurvey>): OrganizedSurvey {
    return {
        id: 's-1',
        title: 'Valora la partida',
        subjectTitle: 'Partida de ejemplo',
        status: 'to_send',
        scheduledSendAt: null,
        closesAt: null,
        rejectionReason: null,
        responses: 0,
        audienceSize: 0,
        sendUrl: '/encuestas/s-1/enviar',
        sendOnEnd: false,
        sendDelayHours: 0,
        dismissUrl: '/encuestas/s-1/descartar',
        editUrl: '/encuestas/s-1/editar',
        resultsUrl: null,
        ...overrides,
    };
}

describe('OrganizedSurveysList', () => {
    it('tells how a prepared survey goes out on the end, without sending it now', () => {
        const wrapper = mount(OrganizedSurveysList, {
            props: {
                surveys: [
                    survey({
                        id: 's-1',
                        status: 'prepared',
                        sendUrl: null,
                        sendOnEnd: true,
                        sendDelayHours: 12,
                    }),
                    survey({
                        id: 's-2',
                        status: 'prepared',
                        sendUrl: null,
                        sendOnEnd: true,
                        sendDelayHours: 0,
                    }),
                    survey({ id: 's-3', status: 'prepared', sendUrl: null, sendOnEnd: false }),
                ],
            },
        });

        expect(wrapper.text()).toContain('Preparada');
        expect(wrapper.text()).toContain('Se enviará sola 12 h después de que termine.');
        expect(wrapper.text()).toContain('Se enviará sola en cuanto termine.');
        expect(wrapper.text()).toContain(
            'Cuando termine te avisaremos para que la envíes con un clic.'
        );
        expect(wrapper.findAll('button').map((button) => button.text())).not.toContain('Enviar');
    });

    it('offers to send or not to send a survey waiting for the organizer', async () => {
        const wrapper = mount(OrganizedSurveysList, { props: { surveys: [survey({})] } });

        expect(wrapper.text()).toContain('Pendiente de enviar');
        expect(wrapper.text()).toContain('Partida de ejemplo');

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Enviar')
            ?.trigger('click');

        expect(router.post).toHaveBeenCalledWith('/encuestas/s-1/enviar', {}, expect.any(Object));
    });

    it('asks before not sending it', async () => {
        vi.mocked(router.post).mockClear();
        const wrapper = mount(OrganizedSurveysList, {
            props: { surveys: [survey({})] },
            attachTo: document.body,
        });

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'No enviar')
            ?.trigger('click');

        expect(router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('¿No enviar esta encuesta?');
    });

    it('tells when a scheduled survey goes out and lets it go now', () => {
        const wrapper = mount(OrganizedSurveysList, {
            props: {
                surveys: [
                    survey({ status: 'scheduled', scheduledSendAt: '2030-01-06T08:00:00+00:00' }),
                ],
            },
        });

        expect(wrapper.text()).toContain('Programada');
        expect(wrapper.text()).toContain('Se enviará el');
        expect(wrapper.text()).toContain('Enviar ahora');
    });

    it('shows the response rate of an open survey without actions', () => {
        const wrapper = mount(OrganizedSurveysList, {
            props: {
                surveys: [
                    survey({
                        status: 'open',
                        responses: 3,
                        audienceSize: 4,
                        sendUrl: null,
                        dismissUrl: null,
                    }),
                ],
            },
        });

        expect(wrapper.text()).toContain('3 de 4 han respondido');
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('links to the results once the survey opened', () => {
        const wrapper = mount(OrganizedSurveysList, {
            props: {
                surveys: [
                    survey({
                        status: 'closed',
                        sendUrl: null,
                        dismissUrl: null,
                        editUrl: null,
                        resultsUrl: '/encuestas/gestionar/s-1/resultados',
                    }),
                    survey({ id: 's-2' }),
                ],
            },
        });

        const links = wrapper.findAll('a').filter((link) => link.text() === 'Resultados');
        expect(links).toHaveLength(1);
        expect(links[0]?.attributes('href')).toBe('/encuestas/gestionar/s-1/resultados');
    });

    it('shows why a survey was rejected', () => {
        const wrapper = mount(OrganizedSurveysList, {
            props: {
                surveys: [survey({ status: 'rejected', rejectionReason: 'Falta una pregunta' })],
            },
        });

        expect(wrapper.text()).toContain('Rechazada');
        expect(wrapper.text()).toContain('Motivo del rechazo: Falta una pregunta');
    });

    it('leaves the subject out on its own page', () => {
        const wrapper = mount(OrganizedSurveysList, {
            props: { surveys: [survey({})], showSubject: false },
        });

        expect(wrapper.text()).not.toContain('Partida de ejemplo');
    });
});

describe('ProfilePendingSurveysSection with organized surveys', () => {
    it('lists what the person organizes above what they have to answer', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: { profilePendingSurveys: [], profileOrganizedSurveys: [survey({})] },
        });

        const headings = wrapper.findAll('h2').map((heading) => heading.text());
        expect(headings).toEqual(['De lo que organizas', 'Encuestas pendientes']);
    });

    it('shows no organized block to someone who organizes nothing', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: { profilePendingSurveys: [], profileOrganizedSurveys: [] },
        });

        expect(wrapper.text()).not.toContain('De lo que organizas');
    });
});
