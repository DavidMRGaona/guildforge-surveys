import { describe, expect, it } from 'vitest';
import type {
    AnswerDraft,
    SurveyDefinition,
    SurveyQuestion,
} from '../../resources/js/types/Respond';
import { draftsFor, emptyDraft, missingAnswer, toPayload } from '../../resources/js/utils/answers';
import { everyType } from './support/definition';

function ranking(required: boolean): SurveyQuestion {
    const question = everyType.sections[0]?.questions.find((asked) => asked.id === 'q-rank');
    if (question === undefined) {
        throw new Error('The every-type form has a ranking.');
    }

    return { ...question, required };
}

function placed(...choices: string[]): AnswerDraft {
    return { ...emptyDraft(), choices };
}

describe('answers of a ranking', () => {
    it('sends the options in order, best first, without ids the question does not have', () => {
        const drafts = draftsFor(everyType);
        drafts['q-rank'] = placed('k-2', 'k-9', 'k-1', 'k-3');

        expect(toPayload(everyType, drafts)['q-rank']).toEqual(['k-2', 'k-1', 'k-3']);
    });

    it('leaves out a ranking nobody started', () => {
        expect(toPayload(everyType, draftsFor(everyType))).not.toHaveProperty('q-rank');
    });

    it('asks for every option once one is placed, required or not', () => {
        expect(missingAnswer(ranking(true), placed())).toBe('required');
        expect(missingAnswer(ranking(false), placed())).toBeNull();
        expect(missingAnswer(ranking(true), placed('k-1'))).toBe('ranking_incomplete');
        expect(missingAnswer(ranking(false), placed('k-1', 'k-2'))).toBe('ranking_incomplete');
        expect(missingAnswer(ranking(false), placed('k-1', 'k-2', 'k-3'))).toBeNull();
    });
});

describe('answers of an availability question', () => {
    const asked: SurveyQuestion = {
        id: 'q-1',
        key: null,
        type: 'availability',
        title: '¿Qué día?',
        help: null,
        required: true,
        options: [],
        allow_other: false,
        scale: null,
        stars: null,
        grid: null,
        next_by_option: [],
        slots: ['d-1', 'd-2'].map((id) => ({
            id,
            date: '2030-01-12',
            starts_at: null,
            ends_at: null,
            day: '2030-01-12',
            start: null,
            end: null,
            label: '',
        })),
    };
    const definition: SurveyDefinition = {
        sections: [{ id: 's-1', title: null, description: null, next: null, questions: [asked] }],
    };

    it('sends the marked slots, without unknown slots or answers', () => {
        const draft = { ...emptyDraft(), cells: { 'd-1': 'yes', 'd-9': 'yes', 'd-2': 'maybe' } };

        expect(toPayload(definition, { 'q-1': draft })).toEqual({ 'q-1': { 'd-1': 'yes' } });
    });

    it('is never missing: unmarked slots count as no', () => {
        expect(missingAnswer(asked, emptyDraft())).toBeNull();
        expect(toPayload(definition, { 'q-1': emptyDraft() })).toEqual({ 'q-1': {} });
    });
});
