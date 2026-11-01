import { SUBMIT, type SurveyDefinition, type SurveySection } from '../types/Respond';
import type { AnswerPayload } from './answers';

/**
 * Walks a survey the way SectionNavigator does on the server: each section leads to the
 * one its jumping question's chosen option names, else to its own next, else to the
 * following one, until a jump to SUBMIT or the last section. Both are tested against
 * tests/fixtures/navigation-cases.json. A jump that is not forward is ignored.
 */
export function reachedSectionIds(
    definition: SurveyDefinition,
    answers: Record<string, AnswerPayload>
): string[] {
    const reached: string[] = [];
    let index: number | null = 0;
    while (index !== null) {
        const section = definition.sections[index];
        if (section === undefined) {
            break;
        }
        reached.push(section.id);
        index = nextIndex(definition, index, answers);
    }

    return reached;
}

/** The id of the section after the given one, or null when the survey ends there. */
export function nextSectionId(
    definition: SurveyDefinition,
    sectionId: string,
    answers: Record<string, AnswerPayload>
): string | null {
    const index = definition.sections.findIndex((section) => section.id === sectionId);
    const next = index === -1 ? null : nextIndex(definition, index, answers);

    return next === null ? null : (definition.sections[next]?.id ?? null);
}

function nextIndex(
    definition: SurveyDefinition,
    index: number,
    answers: Record<string, AnswerPayload>
): number | null {
    const section = definition.sections[index];
    if (section === undefined) {
        return null;
    }

    const next = chosenJump(section, answers) ?? section.next;
    if (next === SUBMIT) {
        return null;
    }

    const target =
        next === null ? -1 : definition.sections.findIndex((candidate) => candidate.id === next);
    const following = target > index ? target : index + 1;

    return following < definition.sections.length ? following : null;
}

function chosenJump(section: SurveySection, answers: Record<string, AnswerPayload>): string | null {
    const question = section.questions.find((candidate) => candidate.next_by_option.length > 0);
    if (question === undefined) {
        return null;
    }

    const option = chosenOption(answers[question.id]);

    return question.next_by_option.find((jump) => jump.option_id === option)?.next ?? null;
}

function chosenOption(answer: AnswerPayload | undefined): string | null {
    if (typeof answer === 'string') {
        return answer;
    }
    if (typeof answer === 'object' && 'option' in answer) {
        return answer.option;
    }

    return null;
}
