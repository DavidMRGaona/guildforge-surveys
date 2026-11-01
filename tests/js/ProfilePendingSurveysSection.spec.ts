import { afterEach, describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import ProfilePendingSurveysSection from '../../resources/js/components/profile/ProfilePendingSurveysSection.vue';
import type { PendingSurvey } from '../../resources/js/types/Respond';

function pending(overrides: Partial<PendingSurvey> = {}): PendingSurvey {
    const id = overrides.id ?? 's-1';

    return {
        id,
        title: 'Valora la partida',
        subjectType: null,
        subjectTitle: null,
        closesAt: null,
        url: `/encuestas/${id}`,
        ...overrides,
    };
}

describe('ProfilePendingSurveysSection', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('lists each pending survey with a link to answer it', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: {
                profilePendingSurveys: [
                    pending({ id: 's-1', closesAt: '2030-01-10T19:00:00+00:00' }),
                    pending({ id: 's-2', title: 'Asamblea', closesAt: null }),
                ],
            },
        });

        const items = wrapper.findAll('li');
        expect(items).toHaveLength(2);
        expect(items[0]?.text()).toContain('Valora la partida');
        expect(items[0]?.text()).toContain('Abierta hasta el');
        expect(items[0]?.find('a').attributes('href')).toBe('/encuestas/s-1');
        expect(items[1]?.text()).not.toContain('Abierta hasta el');
    });

    it('says what each survey is about', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: {
                profilePendingSurveys: [
                    pending({ subjectType: 'Mesa', subjectTitle: 'Partida de ejemplo' }),
                    pending({ id: 's-2', title: 'Asamblea' }),
                ],
            },
        });

        const items = wrapper.findAll('li');
        expect(items[0]?.get('[data-testid="subject"]').text()).toBe('Mesa · Partida de ejemplo');
        expect(items[1]?.find('[data-testid="subject"]').exists()).toBe(false);
    });

    it('marks the surveys that close within two days', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2030-01-10T12:00:00Z'));
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: {
                profilePendingSurveys: [
                    pending({ id: 's-1', closesAt: '2030-01-11T12:00:00+00:00' }),
                    pending({ id: 's-2', closesAt: '2030-01-20T12:00:00+00:00' }),
                    pending({ id: 's-3', closesAt: null }),
                ],
            },
        });

        const marked = wrapper.findAll('li').map((item) => item.text().includes('Cierra pronto'));
        expect(marked).toEqual([true, false, false]);
    });

    it('answers with the site button colour, which keeps white text readable', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: { profilePendingSurveys: [pending()] },
        });

        const classes = wrapper.get('li a').classes();
        expect(classes).toContain('bg-primary');
        expect(classes).not.toContain('bg-primary-600');
    });

    it('says so when nothing is pending', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: { profilePendingSurveys: [] },
        });

        expect(wrapper.find('li').exists()).toBe(false);
        expect(wrapper.text()).toContain('No tienes encuestas pendientes');
    });

    it('links to the trends when the server offers them', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: {
                profilePendingSurveys: [],
                profileOrganizedSurveys: [],
                profileSurveyTrendsUrl: '/encuestas/tendencias',
            },
        });

        expect(wrapper.get('[data-testid="trends-link"]').attributes('href')).toBe(
            '/encuestas/tendencias'
        );
        expect(wrapper.text()).toContain('Ver las tendencias');
    });

    it('has no trends link without them', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: { profilePendingSurveys: [], profileSurveyTrendsUrl: null },
        });

        expect(wrapper.find('[data-testid="trends-link"]').exists()).toBe(false);
    });
});
