import type { AnswerDraft, SurveyDefinition } from '../types/Respond';
import { allAnswers, emptyDraft } from './answers';
import { reachedSectionIds } from './navigation';

/**
 * The answers given so far, kept in the browser tab (sessionStorage) so a reload or going
 * back and forth loses nothing. It never reaches the server, and closing the tab forgets
 * it: on a shared computer nobody finds someone else's answers to an anonymous survey.
 * A draft for another version of the survey is dropped.
 */
export interface Draft {
    drafts: Record<string, AnswerDraft>;
    /** The section on screen, or null to start at the first one. */
    section: string | null;
}

export function readDraft(
    storage: Storage | null,
    key: string,
    definition: SurveyDefinition
): Draft | null {
    let stored: unknown;
    try {
        stored = JSON.parse(storage?.getItem(key) ?? 'null');
    } catch {
        return null;
    }
    if (!isRecord(stored) || stored.fingerprint !== fingerprint(definition)) {
        return null;
    }

    const saved = isRecord(stored.drafts) ? stored.drafts : {};
    const drafts: Record<string, AnswerDraft> = {};
    for (const section of definition.sections) {
        for (const question of section.questions) {
            const draft = saved[question.id];
            if (isRecord(draft)) {
                drafts[question.id] = sanitize(draft);
            }
        }
    }

    return { drafts, section: typeof stored.section === 'string' ? stored.section : null };
}

export function writeDraft(
    storage: Storage | null,
    key: string,
    definition: SurveyDefinition,
    drafts: Record<string, AnswerDraft>,
    section: string | null
): void {
    try {
        storage?.setItem(
            key,
            JSON.stringify({ fingerprint: fingerprint(definition), drafts, section })
        );
    } catch {
        // A full or blocked storage only costs the draft
    }
}

export function removeDraft(storage: Storage | null, key: string): void {
    try {
        storage?.removeItem(key);
    } catch {
        // Nothing to forget
    }
}

/** The storage key of a survey's draft for one respondent (see RespondController::draftScope). */
export function draftKeyFor(surveyId: string, scope: string): string {
    return `${draftPrefix(surveyId)}${scope}`;
}

/**
 * Drops every draft of the survey except the one kept (none when it is null): whoever signs
 * in next in the same tab never finds someone else's answers, and an answered or closed
 * survey leaves nothing behind.
 */
export function pruneDrafts(storage: Storage | null, surveyId: string, keep: string | null): void {
    try {
        if (storage === null) {
            return;
        }
        const stale: string[] = [];
        for (let index = 0; index < storage.length; index++) {
            const key = storage.key(index);
            if (key !== null && key.startsWith(draftPrefix(surveyId)) && key !== keep) {
                stale.push(key);
            }
        }
        stale.forEach((key) => storage.removeItem(key));
    } catch {
        // Nothing to forget
    }
}

/**
 * Where to resume: the saved section and the way that leads to it with these answers.
 * The way taken is always the start of the path the answers walk (going back is the only
 * way to change an earlier answer), so it is rebuilt rather than stored. A section the
 * answers no longer reach starts over.
 */
export function resumeAt(
    definition: SurveyDefinition,
    drafts: Record<string, AnswerDraft>,
    sectionId: string | null
): { current: number; trail: number[] } {
    const path = reachedSectionIds(definition, allAnswers(definition, drafts)).map((id) =>
        definition.sections.findIndex((section) => section.id === id)
    );
    const step =
        sectionId === null
            ? -1
            : path.findIndex((index) => definition.sections[index]?.id === sectionId);

    return step <= 0
        ? { current: 0, trail: [] }
        : { current: path[step] ?? 0, trail: path.slice(0, step) };
}

/** The questions and their types, so a draft never fills another version of the survey. */
function fingerprint(definition: SurveyDefinition): string {
    return definition.sections
        .map(
            (section) =>
                `${section.id}:${section.questions.map((question) => `${question.id}=${question.type}`).join(',')}`
        )
        .join('|');
}

function draftPrefix(surveyId: string): string {
    return `surveys.draft.${surveyId}.`;
}

function sanitize(saved: Record<string, unknown>): AnswerDraft {
    const draft = emptyDraft();
    if (typeof saved.text === 'string') {
        draft.text = saved.text;
    }
    if (typeof saved.choice === 'string') {
        draft.choice = saved.choice;
    }
    if (Array.isArray(saved.choices)) {
        draft.choices = saved.choices.filter(
            (choice): choice is string => typeof choice === 'string'
        );
    }
    if (typeof saved.other === 'string') {
        draft.other = saved.other;
    }
    if (typeof saved.number === 'number' && Number.isFinite(saved.number)) {
        draft.number = saved.number;
    }
    if (isRecord(saved.cells)) {
        for (const [row, column] of Object.entries(saved.cells)) {
            if (typeof column === 'string') {
                draft.cells[row] = column;
            }
        }
    }

    return draft;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}
