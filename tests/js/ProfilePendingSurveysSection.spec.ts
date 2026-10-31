import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import ProfilePendingSurveysSection from '../../resources/js/components/profile/ProfilePendingSurveysSection.vue';

describe('ProfilePendingSurveysSection', () => {
    it('lists each pending survey with a link to answer it', () => {
        const wrapper = mount(ProfilePendingSurveysSection, {
            props: {
                profilePendingSurveys: [
                    {
                        id: 's-1',
                        title: 'Valora la partida',
                        closesAt: '2030-01-10T19:00:00+00:00',
                        url: '/encuestas/s-1',
                    },
                    { id: 's-2', title: 'Asamblea', closesAt: null, url: '/encuestas/s-2' },
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
