import { describe, expect, it } from 'vitest';
import { OTHER, SUBMIT } from '../../resources/js/types/Respond';
import {
    allowsOther,
    canBranch,
    changeType,
    hasOptions,
    jumpTargets,
    move,
    newQuestion,
    newSection,
    pruneJumps,
    setJump,
    withoutSection,
} from '../../resources/js/utils/definition';

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

    it('keeps the options when a choice becomes a ranking, without "Other" or jumps', () => {
        const question = setJump(
            { ...newQuestion('single_choice'), allow_other: true },
            'o-x',
            's-3'
        );

        const ranking = changeType(question, 'ranking');

        expect(ranking.options).toEqual(question.options);
        expect(ranking.allow_other).toBe(false);
        expect(ranking.next_by_option).toEqual([]);
        expect(hasOptions('ranking')).toBe(true);
        expect(allowsOther('ranking')).toBe(false);
        expect(allowsOther('multiple_choice')).toBe(true);
        expect(canBranch('ranking')).toBe(false);
        expect(newQuestion('ranking').options).toHaveLength(2);
    });

    it('moves an item and ignores moves out of bounds', () => {
        expect(move(['a', 'b', 'c'], 0, 1)).toEqual(['b', 'a', 'c']);
        expect(move(['a', 'b', 'c'], 2, -1)).toEqual(['a', 'c', 'b']);
        expect(move(['a', 'b'], 0, -1)).toEqual(['a', 'b']);
    });

    it('lets only single choice and dropdown questions jump', () => {
        expect(canBranch('single_choice')).toBe(true);
        expect(canBranch('dropdown')).toBe(true);
        expect(canBranch('multiple_choice')).toBe(false);

        const question = setJump(newQuestion('single_choice'), 'o-x', 's-3');
        expect(changeType(question, 'dropdown').next_by_option).toEqual(question.next_by_option);
        expect(changeType(question, 'multiple_choice').next_by_option).toEqual([]);
    });

    it('keeps the jumps in the order of the options, "Other" last', () => {
        const base = { ...newQuestion('single_choice'), allow_other: true };
        const [first, second] = base.options.map((option) => option.id);

        let question = setJump(base, OTHER, SUBMIT);
        question = setJump(question, String(second), 's-3');
        question = setJump(question, String(first), 's-2');

        expect(question.next_by_option).toEqual([
            { option_id: first, next: 's-2' },
            { option_id: second, next: 's-3' },
            { option_id: OTHER, next: SUBMIT },
        ]);
        expect(setJump(question, String(second), null).next_by_option).toHaveLength(2);
    });

    it('drops the jumps of removed options and of "Other" once it is gone', () => {
        const base = { ...newQuestion('single_choice'), allow_other: true };
        const question = setJump(setJump(base, String(base.options[0]?.id), 's-2'), OTHER, SUBMIT);

        expect(pruneJumps({ ...question, options: base.options.slice(1) }).next_by_option).toEqual([
            { option_id: OTHER, next: SUBMIT },
        ]);
        expect(pruneJumps({ ...question, allow_other: false }).next_by_option).toEqual([
            { option_id: base.options[0]?.id, next: 's-2' },
        ]);
    });

    it('offers the later sections as targets', () => {
        const sections = [newSection(), { ...newSection(), title: 'Final' }, newSection()];

        expect(jumpTargets(sections, String(sections[0]?.id))).toEqual([
            { id: sections[1]?.id, number: 2, title: 'Final' },
            { id: sections[2]?.id, number: 3, title: null },
        ]);
        expect(jumpTargets(sections, String(sections[2]?.id))).toEqual([]);
    });

    it('removes a section with the jumps that led to it', () => {
        const [first, second, third] = [newSection(), newSection(), newSection()];
        const jumping = setJump(newQuestion('dropdown'), 'o-1', third.id);
        const sections = [
            { ...first, next: third.id, questions: [jumping] },
            { ...second, next: third.id },
            third,
        ];

        const left = withoutSection(sections, third.id);

        expect(left).toHaveLength(2);
        expect(left[0]?.next).toBeNull();
        expect(left[0]?.questions[0]?.next_by_option).toEqual([]);
        expect(left[1]?.next).toBeNull();
    });
});
