import {
    AVAILABILITY_ANSWERS,
    OTHER,
    type AnswerDraft,
    type SurveyDefinition,
    type SurveyQuestion,
} from '../types/Respond';
import { reachedSectionIds } from './navigation';

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
 * questions are left out, so the server decides whether they were required, and so are
 * the sections the answers no longer lead to: the server refuses answers to those.
 */
export function toPayload(
    definition: SurveyDefinition,
    drafts: Record<string, AnswerDraft>
): Record<string, AnswerPayload> {
    const answered = allAnswers(definition, drafts);
    const reached = new Set(reachedSectionIds(definition, answered));
    const payload: Record<string, AnswerPayload> = {};
    for (const section of definition.sections) {
        if (!reached.has(section.id)) {
            continue;
        }
        for (const question of section.questions) {
            const value = answered[question.id];
            if (value !== undefined) {
                payload[question.id] = value;
            }
        }
    }

    return payload;
}

/** Every answer given, whichever section it is in: what the navigation reads. */
export function allAnswers(
    definition: SurveyDefinition,
    drafts: Record<string, AnswerDraft>
): Record<string, AnswerPayload> {
    const answers: Record<string, AnswerPayload> = {};
    for (const section of definition.sections) {
        for (const question of section.questions) {
            const draft = drafts[question.id];
            const value = draft === undefined ? undefined : answerOf(question, draft);
            if (value !== undefined) {
                answers[question.id] = value;
            }
        }
    }

    return answers;
}

/**
 * The server's message key when a required question is left unanswered (see
 * SubmissionValidator), or null. The page checks this before moving to the next section.
 */
export function missingAnswer(
    question: SurveyQuestion,
    draft: AnswerDraft | undefined
): 'required' | 'grid_incomplete' | 'ranking_incomplete' | null {
    if (question.type === 'ranking' && draft !== undefined) {
        // Half an order says nothing about the rest, so it is refused even when optional
        const placed = ranked(question, draft).length;
        if (placed > 0 && placed < question.options.length) {
            return 'ranking_incomplete';
        }
    }
    // Unmarked slots count as "no": an availability question is always answered
    if (!question.required || question.type === 'availability') {
        return null;
    }
    if (draft === undefined || answerOf(question, draft) === undefined) {
        return 'required';
    }
    if (question.type === 'grid') {
        const rows = question.grid?.rows ?? [];

        return rows.every((row) => draft.cells[row.id] !== undefined) ? null : 'grid_incomplete';
    }

    return null;
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
        case 'availability':
            // Slots left unmarked count as "no" on the server, so nothing marked is an answer too
            return availability(question, draft);
        case 'ranking': {
            const order = ranked(question, draft);

            return order.length === 0 ? undefined : order;
        }
        default:
            return draft.text.trim() === '' ? undefined : draft.text;
    }
}

/** The answer to each slot the person marked, without slots or answers the question does not have. */
function availability(question: SurveyQuestion, draft: AnswerDraft): Record<string, string> {
    const answers: Record<string, string> = {};
    for (const slot of question.slots) {
        const answer = draft.cells[slot.id];
        if (answer !== undefined && (AVAILABILITY_ANSWERS as string[]).includes(answer)) {
            answers[slot.id] = answer;
        }
    }

    return answers;
}

/** The placed options of a ranking, best first, without ids the question does not have. */
function ranked(question: SurveyQuestion, draft: AnswerDraft): string[] {
    const ids = question.options.map((option) => option.id);

    return draft.choices.filter(
        (choice, index) => ids.includes(choice) && draft.choices.indexOf(choice) === index
    );
}
