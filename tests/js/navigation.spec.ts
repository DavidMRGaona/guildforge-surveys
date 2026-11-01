import { describe, expect, it } from 'vitest';
import fixture from '../fixtures/navigation-cases.json';
import type {
    SurveyDefinition,
    SurveyQuestion,
    SurveySection,
} from '../../resources/js/types/Respond';
import type { AnswerPayload } from '../../resources/js/utils/answers';
import { nextSectionId, reachedSectionIds } from '../../resources/js/utils/navigation';

type FixtureQuestion = Partial<SurveyQuestion> & Pick<SurveyQuestion, 'id' | 'type' | 'title'>;
type FixtureSection = Partial<Omit<SurveySection, 'questions'>> & {
    id: string;
    questions: FixtureQuestion[];
};

/** The fixture leaves out what the server fills in by default, as SurveyDefinition::fromArray() does. */
function definition(name: string): SurveyDefinition {
    const sections = (fixture.definitions as Record<string, { sections: FixtureSection[] }>)[name]
        ?.sections;
    if (sections === undefined) {
        throw new Error(`Unknown fixture definition ${name}`);
    }

    return {
        sections: sections.map((section) => ({
            title: null,
            description: null,
            next: null,
            ...section,
            questions: section.questions.map((question) => ({
                key: null,
                help: null,
                required: false,
                options: [],
                allow_other: false,
                scale: null,
                stars: null,
                grid: null,
                next_by_option: [],
                ...question,
            })),
        })),
    };
}

describe('navigation', () => {
    it.each(fixture.cases)('$name', ({ definition: name, answers, reached }) => {
        expect(
            reachedSectionIds(definition(name), answers as Record<string, AnswerPayload>)
        ).toEqual(reached);
    });

    it('names the section after another, or null at the end', () => {
        const campaign = definition('campaign');

        expect(nextSectionId(campaign, 's-intro', { 'q-played': 'o-part' })).toBe('s-part');
        expect(nextSectionId(campaign, 's-intro', { 'q-played': 'o-none' })).toBeNull();
        expect(nextSectionId(campaign, 's-full', {})).toBe('s-end');
        expect(nextSectionId(campaign, 's-end', {})).toBeNull();
    });
});
