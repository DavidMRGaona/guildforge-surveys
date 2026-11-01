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
    | 'grid'
    | 'ranking'
    | 'availability';

export interface SurveyOption {
    id: string;
    label: string;
}

/**
 * A date an availability question proposes: a whole day (`date`) or a time slot (`starts_at`,
 * optional `ends_at`, instants in UTC), as stored; then as the site shows it: its day, start and
 * end (H:i, null for a whole day) and a label. The editor sends back only id, day, start and end.
 */
export interface SurveySlot {
    id: string;
    date: string | null;
    starts_at: string | null;
    ends_at: string | null;
    day: string;
    start: string | null;
    end: string | null;
    label: string;
}

/** What each person says about a slot, as AvailabilityAnswer on the server. */
export type AvailabilityAnswer = 'yes' | 'if_needed' | 'no';

export const AVAILABILITY_ANSWERS: AvailabilityAnswer[] = ['yes', 'if_needed', 'no'];

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
    /** Where each option leads after the section: a later section's id or SUBMIT. */
    next_by_option: OptionJump[];
    /** Only on availability questions. */
    slots: SurveySlot[];
}

export interface OptionJump {
    option_id: string;
    next: string;
}

export interface SurveySection {
    id: string;
    title: string | null;
    description: string | null;
    /** Where to go after the section: null for the following one, a later section's id or SUBMIT. */
    next: string | null;
    questions: SurveyQuestion[];
}

export interface SurveyDefinition {
    sections: SurveySection[];
}

export type SurveyKind = 'form' | 'poll' | 'availability';

export type RespondState =
    'open' | 'answered' | 'closed' | 'paused' | 'not_invited' | 'verify_email';

export interface RespondSurvey {
    id: string;
    kind: SurveyKind;
    title: string;
    description: string | null;
    anonymous: boolean;
    closesAt: string | null;
}

/** What the survey is about, as it was when it opened. */
export interface RespondSubject {
    /** The kind of subject ("Mesa de juego"), or null once its module is gone. */
    typeLabel: string | null;
    title: string;
    startsAt: string | null;
    url: string | null;
}

export interface RespondProps {
    state: RespondState;
    result: 'submitted' | 'link_sent' | null;
    survey?: RespondSurvey;
    /** Missing for a survey about nothing in particular. */
    subject?: RespondSubject;
    /** Only for whoever answers with an account: their pending surveys. */
    profileUrl?: string;
    definition?: SurveyDefinition;
    submitUrl?: string;
    /** While it can be answered: an opaque name for whoever answers, which keys their draft. */
    draftScope?: string;
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
    /** Responses that reached it when sections were skipped, else null (see ResultsService). */
    reached: number | null;
    buckets: ResultBucket[];
    grid: {
        rows: SurveyOption[];
        columns: SurveyOption[];
        counts: Record<string, Record<string, number>>;
    } | null;
    mean: number | null;
    median: number | null;
    /** Only on rankings. */
    ranking: RankingResult | null;
    /** Only on availability questions. */
    availability: AvailabilityResult | null;
}

/** An availability question's results, the slots in the survey's order (see AvailabilityResultDTO). */
export interface AvailabilityResult {
    /** The date fixed once the survey closed, if any. */
    chosenSlotId: string | null;
    slots: AvailabilitySlotResult[];
}

export interface AvailabilitySlotResult {
    id: string;
    label: string;
    shortLabel: string;
    yes: number;
    ifNeeded: number;
    no: number;
    /** Among the most "yes" and, of those, the most "if needed". */
    best: boolean;
    /** Names, only on named surveys. */
    yesNames: string[];
    ifNeededNames: string[];
    noNames: string[];
}

/** A ranking's results, best option first (see RankingResultDTO). */
export interface RankingResult {
    /** Places to fill, one per option. */
    positions: number;
    options: {
        id: string;
        label: string;
        /** Borda points: as many as places for each first place, one fewer for each place below. */
        points: number;
        meanPosition: number | null;
        /** How often it got each place, the first place first. */
        places: number[];
    }[];
}

export interface RespondResults {
    responses: number;
    questions: QuestionResult[];
}

/** What one question holds while it is being answered. */
export interface AnswerDraft {
    text: string;
    choice: string;
    /** Ticked checkboxes, or the placed options of a ranking, best first. */
    choices: string[];
    other: string;
    number: number | null;
    /** A grid's column by row id, or an availability answer by slot id. */
    cells: Record<string, string>;
}

/** Option id that stands for "Other", as Submission::OTHER on the server. */
export const OTHER = '__other__';

/** Jump target that ends the survey, as Section::SUBMIT on the server. */
export const SUBMIT = '__submit__';

/** A survey still to answer, as the profile's pending surveys tab gets it. */
export interface PendingSurvey {
    id: string;
    title: string;
    /** The kind of thing it is about ("Mesa"), null for general surveys. */
    subjectType: string | null;
    subjectTitle: string | null;
    closesAt: string | null;
    url: string;
}
