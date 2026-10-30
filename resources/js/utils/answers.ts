import {
    OTHER,
    type AnswerDraft,
    type SurveyDefinition,
    type SurveyQuestion,
} from '../types/Respond';

/** One answer as the server reads it (see Submission::fromInput()). */
export type AnswerPayload =
    | string
    | number
    | string[]
    | Record<string, string>
    | { option: string; other: string }
    | { options: string[]; other: string };

export function emptyDraft(): AnswerDraft {
    return { text: '', choice: '', choices: [], other: '', number: null, cells: {} };
}

/** One empty draft per question of the survey, keyed by question id. */
export function draftsFor(definition: SurveyDefinition): Record<string, AnswerDraft> {
    const drafts: Record<string, AnswerDraft> = {};
    for (const section of definition.sections) {
        for (const question of section.questions) {
            drafts[question.id] = emptyDraft();
        }
    }

    return drafts;
}

/**
 * The answers in the shape Submission::fromInput() reads on the server. Unanswered
 * questions are left out, so the server decides whether they were required.
 */
export function toPayload(
    definition: SurveyDefinition,
    drafts: Record<string, AnswerDraft>
): Record<string, AnswerPayload> {
    const payload: Record<string, AnswerPayload> = {};
    for (const section of definition.sections) {
        for (const question of section.questions) {
            const draft = drafts[question.id];
            const value = draft === undefined ? undefined : answerOf(question, draft);
            if (value !== undefined) {
                payload[question.id] = value;
            }
        }
    }

    return payload;
}

function answerOf(question: SurveyQuestion, draft: AnswerDraft): AnswerPayload | undefined {
    switch (question.type) {
        case 'single_choice':
        case 'dropdown':
            if (draft.choice === '') {
                return undefined;
            }
            return draft.choice === OTHER ? { option: OTHER, other: draft.other } : draft.choice;
        case 'multiple_choice':
            if (draft.choices.length === 0) {
                return undefined;
            }
            return draft.choices.includes(OTHER)
                ? { options: draft.choices, other: draft.other }
                : draft.choices;
        case 'linear_scale':
        case 'star_rating':
            return draft.number ?? undefined;
        case 'grid':
            return Object.keys(draft.cells).length === 0 ? undefined : draft.cells;
        default:
            return draft.text.trim() === '' ? undefined : draft.text;
    }
}
