import { beforeEach, describe, expect, it } from 'vitest';
import { emptyDraft } from '../../resources/js/utils/answers';
import {
    pruneDrafts,
    readDraft,
    removeDraft,
    resumeAt,
    writeDraft,
} from '../../resources/js/utils/draft';
import { branching, everyType } from './support/definition';

const KEY = 'surveys.draft.survey-1';

describe('draft', () => {
    beforeEach(() => {
        sessionStorage.clear();
    });

    it('keeps the answers and the section on screen for the same survey', () => {
        const drafts = { 'q-played': { ...emptyDraft(), choice: 'o-part' } };

        writeDraft(sessionStorage, KEY, branching, drafts, 's-part');

        expect(readDraft(sessionStorage, KEY, branching)).toEqual({
            drafts: { 'q-played': { ...emptyDraft(), choice: 'o-part' } },
            section: 's-part',
        });
    });

    it('drops a draft once the survey has other questions', () => {
        writeDraft(sessionStorage, KEY, branching, {}, null);

        expect(readDraft(sessionStorage, KEY, everyType)).toBeNull();
    });

    it('keeps only well-formed answers to questions of the survey', () => {
        writeDraft(sessionStorage, KEY, branching, {}, null);
        const stored = JSON.parse(sessionStorage.getItem(KEY) ?? '{}') as Record<string, unknown>;
        sessionStorage.setItem(
            KEY,
            JSON.stringify({
                ...stored,
                drafts: {
                    'q-played': { choice: 'o-all', choices: 'not a list', number: 'seven' },
                    'q-unknown': { text: 'x' },
                },
                section: 42,
            })
        );

        expect(readDraft(sessionStorage, KEY, branching)).toEqual({
            drafts: { 'q-played': { ...emptyDraft(), choice: 'o-all' } },
            section: null,
        });
    });

    it('never fails when there is nothing to read or no storage', () => {
        sessionStorage.setItem(KEY, '{broken');

        expect(readDraft(sessionStorage, KEY, branching)).toBeNull();
        expect(readDraft(null, KEY, branching)).toBeNull();
        expect(() => writeDraft(null, KEY, branching, {}, null)).not.toThrow();
        expect(() => removeDraft(null, KEY)).not.toThrow();
    });

    it('forgets the draft', () => {
        writeDraft(sessionStorage, KEY, branching, {}, null);
        removeDraft(sessionStorage, KEY);

        expect(sessionStorage.getItem(KEY)).toBeNull();
    });
});

describe('pruneDrafts', () => {
    beforeEach(() => {
        sessionStorage.clear();
    });

    it('drops the drafts of a survey kept for anyone else', () => {
        sessionStorage.setItem('surveys.draft.survey-1.mine', '{}');
        sessionStorage.setItem('surveys.draft.survey-1.someone-else', '{}');
        sessionStorage.setItem('surveys.draft.survey-2.someone-else', '{}');
        sessionStorage.setItem('unrelated', '{}');

        pruneDrafts(sessionStorage, 'survey-1', 'surveys.draft.survey-1.mine');

        expect(Object.keys(sessionStorage).sort()).toEqual([
            'surveys.draft.survey-1.mine',
            'surveys.draft.survey-2.someone-else',
            'unrelated',
        ]);
    });

    it('drops every draft of the survey when none is kept', () => {
        sessionStorage.setItem('surveys.draft.survey-1.mine', '{}');

        pruneDrafts(sessionStorage, 'survey-1', null);
        pruneDrafts(null, 'survey-1', null);

        expect(sessionStorage.length).toBe(0);
    });
});

describe('resumeAt', () => {
    it('rebuilds the way back to the saved section from the answers', () => {
        const drafts = { 'q-played': { ...emptyDraft(), choice: 'o-part' } };

        expect(resumeAt(branching, drafts, 's-end')).toEqual({ current: 3, trail: [0, 2] });
    });

    it('starts over when the answers no longer lead to the saved section', () => {
        const drafts = { 'q-played': { ...emptyDraft(), choice: 'o-all' } };

        expect(resumeAt(branching, drafts, 's-part')).toEqual({ current: 0, trail: [] });
        expect(resumeAt(branching, drafts, null)).toEqual({ current: 0, trail: [] });
    });
});
