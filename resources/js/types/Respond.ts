/**
 * Props of the survey response page (RespondController) and the shapes of a definition,
 * as SurveyDefinition::toArray() sends it.
 */
export type QuestionType =
    | 'short_text'
    | 'paragraph'
    | 'single_choice'
    | 'multiple_choice'
    | 'dropdown'
    | 'linear_scale'
    | 'star_rating'
    | 'date'
    | 'time'
    | 'grid';

export interface SurveyOption {
    id: string;
    label: string;
}

export interface SurveyQuestion {
    id: string;
    key: string | null;
    type: QuestionType;
    title: string;
    help: string | null;
    required: boolean;
    options: SurveyOption[];
    allow_other: boolean;
    scale: { min: number; max: number; min_label: string | null; max_label: string | null } | null;
    stars: number | null;
    grid: { rows: SurveyOption[]; columns: SurveyOption[] } | null;
}

export interface SurveySection {
    id: string;
    title: string | null;
    description: string | null;
    questions: SurveyQuestion[];
}

export interface SurveyDefinition {
    sections: SurveySection[];
}

export type RespondState =
    'open' | 'answered' | 'closed' | 'paused' | 'not_invited' | 'verify_email';

export interface RespondSurvey {
    id: string;
    kind: 'form' | 'poll';
    title: string;
    description: string | null;
    anonymous: boolean;
    closesAt: string | null;
}

export interface RespondProps {
    state: RespondState;
    result: 'submitted' | 'link_sent' | null;
    survey?: RespondSurvey;
    definition?: SurveyDefinition;
    submitUrl?: string;
    /** Only on the verified public link: where the address is sent to get a single-use link. */
    requestUrl?: string;
    /** Only when the survey shares its results with the people who answer, and they may be seen. */
    results?: RespondResults;
}

/** One bar: an option (label null for "Other"), a scale value, a day or an hour. */
export interface ResultBucket {
    key: string;
    label: string | null;
    count: number;
    /** Names, only on named polls. */
    voters: string[];
}

export interface QuestionResult {
    id: string;
    title: string;
    type: QuestionType;
    answered: number;
    belowMinimum: boolean;
    buckets: ResultBucket[];
    grid: {
        rows: SurveyOption[];
        columns: SurveyOption[];
        counts: Record<string, Record<string, number>>;
    } | null;
    mean: number | null;
    median: number | null;
}

export interface RespondResults {
    responses: number;
    questions: QuestionResult[];
}

/** What one question holds while it is being answered. */
export interface AnswerDraft {
    text: string;
    choice: string;
    choices: string[];
    other: string;
    number: number | null;
    cells: Record<string, string>;
}

/** Option id that stands for "Other", as Submission::OTHER on the server. */
export const OTHER = '__other__';

/** A survey still to answer, as the profile's pending surveys tab gets it. */
export interface PendingSurvey {
    id: string;
    title: string;
    closesAt: string | null;
    url: string;
}
