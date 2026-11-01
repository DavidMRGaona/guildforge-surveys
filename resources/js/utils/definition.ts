import {
    OTHER,
    type QuestionType,
    type SurveyDefinition,
    type SurveyOption,
    type SurveyQuestion,
    type SurveySection,
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
    'ranking',
    'linear_scale',
    'star_rating',
    'date',
    'time',
    'grid',
];

/** The only types a poll may use. */
export const POLL_TYPES: QuestionType[] = ['single_choice', 'multiple_choice'];

const CHOICES: QuestionType[] = ['single_choice', 'multiple_choice', 'dropdown'];

/** As SurveyDefinitionValidator::RANKING_OPTIONS_MAX on the server. */
export const RANKING_OPTIONS_MAX = 10;

/** Whether the question has a list of options: to pick from, or to put in order. */
export function hasOptions(type: QuestionType): boolean {
    return CHOICES.includes(type) || type === 'ranking';
}

/** Whether the question may offer "Other", as QuestionType::allowsOther(). */
export function allowsOther(type: QuestionType): boolean {
    return CHOICES.includes(type);
}

/** Whether the chosen option may decide the next section, as QuestionType::canBranch(). */
export function canBranch(type: QuestionType): boolean {
    return type === 'single_choice' || type === 'dropdown';
}

export function newId(): string {
    return crypto.randomUUID();
}

export function newOption(label = ''): SurveyOption {
    return { id: newId(), label };
}

export function newSection(): SurveySection {
    return { id: newId(), title: null, description: null, next: null, questions: [] };
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
        next_by_option: [],
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
            allow_other: keepsOptions && allowsOther(type) ? question.allow_other : false,
            scale: null,
            stars: null,
            grid: null,
            next_by_option: canBranch(type) && keepsOptions ? question.next_by_option : [],
        },
        type
    );
}

/**
 * Sets where an option ("Other" is OTHER) leads; null follows the section. The jumps stay in
 * the order of the options with "Other" last, as the server keeps them.
 */
export function setJump(
    question: SurveyQuestion,
    optionId: string,
    next: string | null
): SurveyQuestion {
    const targets = new Map(question.next_by_option.map((jump) => [jump.option_id, jump.next]));
    if (next === null) {
        targets.delete(optionId);
    } else {
        targets.set(optionId, next);
    }

    const order = [...question.options.map((option) => option.id), OTHER];
    const known = order.filter((id) => targets.has(id));
    const unknown = [...targets.keys()].filter((id) => !order.includes(id));

    return {
        ...question,
        next_by_option: [...known, ...unknown].map((id) => ({
            option_id: id,
            next: targets.get(id) ?? '',
        })),
    };
}

/** Leaves out the jumps of options the question no longer has, and of "Other" once it is off. */
export function pruneJumps(question: SurveyQuestion): SurveyQuestion {
    const ids = question.options.map((option) => option.id);
    if (question.allow_other) {
        ids.push(OTHER);
    }

    return {
        ...question,
        next_by_option: question.next_by_option.filter((jump) => ids.includes(jump.option_id)),
    };
}

/** The sections a jump from the given one may lead to: only later ones, so a survey never loops. */
export function jumpTargets(
    sections: SurveySection[],
    sectionId: string
): { id: string; number: number; title: string | null }[] {
    const index = sections.findIndex((section) => section.id === sectionId);

    return sections
        .map((section, i) => ({ id: section.id, number: i + 1, title: section.title }))
        .filter((_, i) => index !== -1 && i > index);
}

/** The sections without the given one, and without the jumps that led to it. */
export function withoutSection(sections: SurveySection[], sectionId: string): SurveySection[] {
    return sections
        .filter((section) => section.id !== sectionId)
        .map((section) => ({
            ...section,
            next: section.next === sectionId ? null : section.next,
            questions: section.questions.map((question) => ({
                ...question,
                next_by_option: question.next_by_option.filter((jump) => jump.next !== sectionId),
            })),
        }));
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
