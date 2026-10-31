import type {
    QuestionType,
    SurveyDefinition,
    SurveyOption,
    SurveyQuestion,
    SurveySection,
} from '../types/Respond';

/**
 * Builders and edits of a survey definition for the organizer's editor. Ids are stable within
 * the survey (answers point at them), so new items get fresh ones and edits keep the old.
 */
export const QUESTION_TYPES: QuestionType[] = [
    'short_text',
    'paragraph',
    'single_choice',
    'multiple_choice',
    'dropdown',
    'linear_scale',
    'star_rating',
    'date',
    'time',
    'grid',
];

/** The only types a poll may use. */
export const POLL_TYPES: QuestionType[] = ['single_choice', 'multiple_choice'];

const WITH_OPTIONS: QuestionType[] = ['single_choice', 'multiple_choice', 'dropdown'];

export function hasOptions(type: QuestionType): boolean {
    return WITH_OPTIONS.includes(type);
}

export function newId(): string {
    return crypto.randomUUID();
}

export function newOption(label = ''): SurveyOption {
    return { id: newId(), label };
}

export function newSection(): SurveySection {
    return { id: newId(), title: null, description: null, questions: [] };
}

export function newQuestion(type: QuestionType): SurveyQuestion {
    const question: SurveyQuestion = {
        id: newId(),
        key: null,
        type,
        title: '',
        help: null,
        required: false,
        options: [],
        allow_other: false,
        scale: null,
        stars: null,
        grid: null,
    };

    return withSettingsFor(question, type);
}

/**
 * The question with another type: title, help and whether it is required stay, and so do the
 * options when both types have them; settings the new type does not use are dropped.
 */
export function changeType(question: SurveyQuestion, type: QuestionType): SurveyQuestion {
    const keepsOptions = hasOptions(question.type) && hasOptions(type);

    return withSettingsFor(
        {
            ...question,
            type,
            options: keepsOptions ? question.options : [],
            allow_other: keepsOptions ? question.allow_other : false,
            scale: null,
            stars: null,
            grid: null,
        },
        type
    );
}

function withSettingsFor(question: SurveyQuestion, type: QuestionType): SurveyQuestion {
    if (hasOptions(type) && question.options.length === 0) {
        return { ...question, options: [newOption(), newOption()] };
    }
    if (type === 'linear_scale') {
        return { ...question, scale: { min: 1, max: 5, min_label: null, max_label: null } };
    }
    if (type === 'star_rating') {
        return { ...question, stars: 5 };
    }
    if (type === 'grid') {
        return { ...question, grid: { rows: [newOption()], columns: [newOption(), newOption()] } };
    }

    return question;
}

/** Moves an item up (delta -1) or down (delta 1); out of bounds leaves the list as it was. */
export function move<T>(list: T[], index: number, delta: number): T[] {
    const target = index + delta;
    if (target < 0 || target >= list.length || index < 0 || index >= list.length) {
        return list;
    }

    const moved = [...list];
    const [item] = moved.splice(index, 1);
    if (item !== undefined) {
        moved.splice(target, 0, item);
    }

    return moved;
}

/**
 * A deep copy the editor can change without touching what the server sent. The definition is
 * plain JSON, and props arrive as reactive proxies, which structuredClone() refuses.
 */
export function cloneDefinition(definition: SurveyDefinition): SurveyDefinition {
    return JSON.parse(JSON.stringify(definition)) as SurveyDefinition;
}

/** The error the server gave for a path of the definition, if any. */
export function errorAt(errors: Record<string, string>, path: string): string | undefined {
    return errors[`definition.${path}`];
}
