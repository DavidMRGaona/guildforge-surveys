import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import PageHeader from '../../resources/js/components/PageHeader.vue';

describe('PageHeader', () => {
    it('leads with the way back, then what the page belongs to and its title', () => {
        const wrapper = mount(PageHeader, {
            props: {
                title: 'Encuestas de Partida de ejemplo',
                eyebrow: 'Mesa',
                backUrl: '/mesas/partida-de-ejemplo',
                backLabel: 'Volver a la ficha',
            },
        });

        const children = wrapper.get('header').element.children;
        expect(children[0]?.tagName).toBe('A');
        expect(children[0]?.getAttribute('href')).toBe('/mesas/partida-de-ejemplo');
        expect(children[0]?.textContent?.trim()).toBe('Volver a la ficha');
        expect(children[1]?.textContent?.trim()).toBe('Mesa');
        expect(wrapper.get('h1').text()).toBe('Encuestas de Partida de ejemplo');
    });

    it('has only the title when there is nothing else to show', () => {
        const wrapper = mount(PageHeader, { props: { title: 'Tendencias' } });

        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.get('header').element.children).toHaveLength(1);
    });

    it('takes a description below the title', () => {
        const wrapper = mount(PageHeader, {
            props: { title: 'Tendencias' },
            slots: { default: '<p>Cómo cambian las respuestas.</p>' },
        });

        expect(wrapper.get('h1 + p').text()).toBe('Cómo cambian las respuestas.');
    });
});
