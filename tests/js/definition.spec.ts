import { describe, expect, it } from 'vitest';
import { changeType, move, newQuestion } from '../../resources/js/utils/definition';

describe('definition utils', () => {
    it('starts each type with what it needs', () => {
        expect(newQuestion('single_choice').options).toHaveLength(2);
        expect(newQuestion('linear_scale').scale).toEqual({
            min: 1,
            max: 5,
            min_label: null,
            max_label: null,
        });
        expect(newQuestion('star_rating').stars).toBe(5);
        expect(newQuestion('grid').grid?.columns).toHaveLength(2);
        expect(newQuestion('short_text').options).toEqual([]);
    });

    it('gives every new question its own id', () => {
        expect(newQuestion('date').id).not.toBe(newQuestion('date').id);
    });

    it('keeps the options between types that have them and drops the rest', () => {
        const question = { ...newQuestion('single_choice'), title: 'Pregunta', allow_other: true };

        const dropdown = changeType(question, 'dropdown');
        expect(dropdown.options).toEqual(question.options);
        expect(dropdown.title).toBe('Pregunta');
        expect(dropdown.id).toBe(question.id);

        const scale = changeType(question, 'linear_scale');
        expect(scale.options).toEqual([]);
        expect(scale.allow_other).toBe(false);
        expect(scale.scale).not.toBeNull();
    });

    it('moves an item and ignores moves out of bounds', () => {
        expect(move(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
        expect(move(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b']);
        expect(move(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
    });
});
