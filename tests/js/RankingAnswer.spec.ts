import { afterEach, describe, expect, it } from 'vitest';
import { mount, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import RankingAnswer from '../../resources/js/components/respond/RankingAnswer.vue';

const options = [
    { id: 'k-1', label: 'Rol' },
    { id: 'k-2', label: 'Wargames' },
    { id: 'k-3', label: 'Tablero' },
];

function mountRanking(order: string[] = []): VueWrapper {
    const wrapper: VueWrapper = mount(RankingAnswer, {
        props: {
            name: 'q-rank',
            label: 'Ordena estos juegos',
            options,
            order,
            onChange: (next: string[]) => wrapper.setProps({ order: next }),
        },
        attachTo: document.body,
    });

    return wrapper;
}

function orderOf(wrapper: VueWrapper): string[] {
    return (wrapper.props() as unknown as { order: string[] }).order;
}

function ranked(wrapper: VueWrapper): string[] {
    return wrapper.findAll('[data-ranked]').map((item) => item.attributes('data-ranked') ?? '');
}

function button(wrapper: VueWrapper, optionId: string, action: string): DOMWrapper<Element> {
    return wrapper.find(`[data-ranked="${optionId}"] [data-action="${action}"]`);
}

describe('RankingAnswer', () => {
    afterEach(() => {
        document.body.replaceChildren();
    });

    it('starts with every option still to place', () => {
        const wrapper = mountRanking();

        expect(ranked(wrapper)).toEqual([]);
        expect(wrapper.findAll('[data-unplaced]')).toHaveLength(3);
        expect(wrapper.find('[data-unplaced="k-1"]').attributes('aria-label')).toBe(
            'Colocar «Rol» en el puesto 1'
        );
    });

    it('places the options in the order they are tapped, and the last one by itself', async () => {
        const wrapper = mountRanking();

        await wrapper.find('[data-unplaced="k-2"]').trigger('click');
        expect(orderOf(wrapper)).toEqual(['k-2']);
        expect(wrapper.find('[data-unplaced="k-1"]').attributes('aria-label')).toBe(
            'Colocar «Rol» en el puesto 2'
        );

        await wrapper.find('[data-unplaced="k-3"]').trigger('click');

        expect(orderOf(wrapper)).toEqual(['k-2', 'k-3', 'k-1']);
        expect(ranked(wrapper)).toEqual(['k-2', 'k-3', 'k-1']);
        expect(wrapper.findAll('[data-unplaced]')).toHaveLength(0);
        expect(wrapper.find('[role="status"]').text()).toContain('«Rol» queda en el puesto 3');
    });

    it('moves a placed option up and down, keeping the focus on it', async () => {
        const wrapper = mountRanking(['k-1', 'k-2', 'k-3']);

        expect(button(wrapper, 'k-1', 'up').attributes('disabled')).toBeDefined();
        expect(button(wrapper, 'k-3', 'down').attributes('disabled')).toBeDefined();

        await button(wrapper, 'k-1', 'down').trigger('click');
        await wrapper.vm.$nextTick();

        expect(orderOf(wrapper)).toEqual(['k-2', 'k-1', 'k-3']);
        expect(document.activeElement).toBe(button(wrapper, 'k-1', 'down').element);
        expect(wrapper.find('[role="status"]').text()).toBe('«Rol», puesto 2 de 3.');

        await button(wrapper, 'k-1', 'up').trigger('click');
        await wrapper.vm.$nextTick();

        expect(orderOf(wrapper)).toEqual(['k-1', 'k-2', 'k-3']);
        // At the top there is no "up" any more: the focus goes to "down"
        expect(document.activeElement).toBe(button(wrapper, 'k-1', 'down').element);
    });

    it('sends a removed option back without placing the rest', async () => {
        const wrapper = mountRanking(['k-1', 'k-2', 'k-3']);

        await button(wrapper, 'k-2', 'remove').trigger('click');

        expect(orderOf(wrapper)).toEqual(['k-1', 'k-3']);
        expect(wrapper.find('[data-unplaced="k-2"]').exists()).toBe(true);
    });

    it('starts over', async () => {
        const wrapper = mountRanking(['k-1', 'k-2', 'k-3']);

        await wrapper.find('[data-action="clear"]').trigger('click');

        expect(orderOf(wrapper)).toEqual([]);
        expect(wrapper.findAll('[data-unplaced]')).toHaveLength(3);
    });

    it('ignores ids the question does not have', () => {
        const wrapper = mountRanking(['k-9', 'k-2']);

        expect(ranked(wrapper)).toEqual(['k-2']);
        expect(wrapper.findAll('[data-unplaced]')).toHaveLength(2);
    });

    it('reorders when a handle is dragged past the middle of another option', async () => {
        const wrapper = mountRanking(['k-1', 'k-2', 'k-3']);
        // jsdom lays nothing out: each option is 50 px tall, one under the other
        wrapper.findAll('[data-ranked]').forEach((item) => {
            const id = item.attributes('data-ranked') ?? '';
            item.element.getBoundingClientRect = (): DOMRect => {
                const top = orderOf(wrapper).indexOf(id) * 50;

                return DOMRect.fromRect({ x: 0, y: top, width: 300, height: 50 });
            };
        });
        const handle = button(wrapper, 'k-1', 'drag');
        // Moving the node loses the pointer capture: moves and release reach the window only
        const pointer = async (type: string, clientY: number): Promise<void> => {
            window.dispatchEvent(new MouseEvent(type, { clientY, bubbles: true }));
            await wrapper.vm.$nextTick();
        };

        await handle.trigger('pointerdown', { clientY: 25, pointerId: 1, button: 0 });
        await pointer('pointermove', 60);
        expect(orderOf(wrapper)).toEqual(['k-1', 'k-2', 'k-3']);

        await pointer('pointermove', 80);
        expect(orderOf(wrapper)).toEqual(['k-2', 'k-1', 'k-3']);

        await pointer('pointermove', 130);
        await pointer('pointerup', 130);

        expect(orderOf(wrapper)).toEqual(['k-2', 'k-3', 'k-1']);
        expect(wrapper.find('[data-ranked="k-1"]').classes()).not.toContain('shadow-lg');
        expect(wrapper.find('[role="status"]').text()).toBe('«Rol», puesto 3 de 3.');

        // Once released, moving the pointer reorders nothing
        await pointer('pointermove', 10);
        expect(orderOf(wrapper)).toEqual(['k-2', 'k-3', 'k-1']);
    });
});
