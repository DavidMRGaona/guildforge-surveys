import { describe, expect, it } from 'vitest';
import type { AnswerDraft, SurveyQuestion } from '../../resources/js/types/Respond';
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
