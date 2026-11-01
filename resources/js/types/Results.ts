import type { QuestionResult } from './Respond';

/** How a question's results draw: ChartType on the server. */
export type ChartType = 'bar' | 'pie' | 'stacked_bar' | 'list';

/** Why an anonymous survey's results are not shown yet: SurveyResultsDTO on the server. */
export type ResultsHiddenReason = 'not_closed' | 'below_minimum' | 'not_shared';

export interface OrganizerQuestionResult extends QuestionResult {
    chartType: ChartType;
    /** The charts this question can draw, the default first. */
    chartTypes: ChartType[];
    /** Free text and "Other" answers. */
    texts: string[];
}

export interface OrganizerResults {
    visible: boolean;
    hiddenReason: ResultsHiddenReason | null;
    responses: number;
    audienceSize: number;
    /** Responses over audience, 0 to 1; null without audience. */
    rate: number | null;
    questions: OrganizerQuestionResult[];
}

export interface ManageResultsProps {
    survey: {
        id: string;
        title: string;
        anonymous: boolean;
        closed: boolean;
    };
    subject: { title: string };
    results: OrganizerResults;
    /** Questions left out of the results, so they can be shown again. */
    hiddenQuestions: { id: string; title: string }[];
    settingsUrl: string;
    /** Only while a closed availability survey has no date: where it is fixed. */
    chooseDateUrl: string | null;
    backUrl: string;
}
