import { describe, expect, it } from 'vitest';
import { OTHER, SUBMIT } from '../../resources/js/types/Respond';
import {
    allowsOther,
    canBranch,
    canBeScored,
    changeType,
    definitionToSave,
    hasOptions,
    jumpTargets,
    move,
    newQuestion,
    newSection,
    pruneCorrect,
    pruneJumps,
    setCorrect,
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

    it('scores only choice questions', () => {
        expect(
            ['single_choice', 'multiple_choice', 'dropdown'].every((type) =>
                canBeScored(type as 'dropdown')
            )
        ).toBe(true);
        expect(canBeScored('ranking')).toBe(false);
        expect(canBeScored('short_text')).toBe(false);
    });

    it('keeps one correct option on a single answer and several on checkboxes, in their order', () => {
        const single = newQuestion('single_choice');
        const [first, second] = single.options.map((option) => option.id);

        const marked = setCorrect(setCorrect(single, String(first), true), String(second), true);
        expect(marked.correct_option_ids).toEqual([second]);
        expect(marked.points).toBe(1);

        const multiple = changeType(single, 'multiple_choice');
        const both = setCorrect(setCorrect(multiple, String(second), true), String(first), true);
        expect(both.correct_option_ids).toEqual([first, second]);
        expect(setCorrect(both, String(first), false).correct_option_ids).toEqual([second]);
    });

    it('drops the correct marks of removed options and of a type that is not scored', () => {
        const question = newQuestion('multiple_choice');
        const ids = question.options.map((option) => option.id);
        const marked = setCorrect(setCorrect(question, String(ids[0]), true), String(ids[1]), true);

        expect(
            pruneCorrect({ ...marked, options: marked.options.slice(1) }).correct_option_ids
        ).toEqual([ids[1]]);
        expect(changeType(marked, 'single_choice').correct_option_ids).toEqual([ids[0]]);
        expect(changeType(marked, 'ranking').correct_option_ids).toEqual([]);
        expect(changeType(marked, 'ranking').points).toBeNull();
    });

    it('saves the marks only in a quiz, and points only where an option is correct', () => {
        const question = newQuestion('single_choice');
        const marked = {
            ...setCorrect(question, String(question.options[0]?.id), true),
            points: 4,
        };
        const unscored = { ...newQuestion('dropdown'), points: 3 };
        const section = { ...newSection(), questions: [marked, unscored] };

        const quiz = definitionToSave({
            sections: [section],
            quiz: { show_correct_answers: true },
        });
        expect(
            quiz.sections[0]?.questions.map((q) => [q.correct_option_ids.length, q.points])
        ).toEqual([
            [1, 4],
            [0, null],
        ]);

        const plain = definitionToSave({ sections: [section], quiz: null });
        expect(plain.sections[0]?.questions.map((q) => [q.correct_option_ids, q.points])).toEqual([
            [[], null],
            [[], null],
        ]);
    });

    it('starts a file question with images up to 5 MB and drops it on another type', () => {
        const file = newQuestion('file');
        expect(file.file).toEqual({ accept: ['image'], max_mb: 5 });

        const changed = changeType({ ...file, file: { accept: ['pdf'], max_mb: 2 } }, 'short_text');
        expect(changed.file).toBeNull();
        expect(changeType(newQuestion('short_text'), 'file').file).toEqual({
            accept: ['image'],
            max_mb: 5,
        });
    });
});
