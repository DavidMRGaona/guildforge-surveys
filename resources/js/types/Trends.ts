/** What a question's trend shows: QuestionTrendDTO on the server. */
export type TrendMeasure = 'mean' | 'shares' | 'none';

export type TrendPeriod = 'month' | 'quarter' | 'year';

/** The site never compares by organizer: each organizer sees their own surveys. */
export type TrendComparison = 'none' | 'game_system' | 'subject_type';

/** The only period of choices compared by series: the whole range. */
export const TOTAL_PERIOD = 'total';

export interface TrendPoint {
    period: string;
    answered: number;
    /** Too few answers to show: no mean and no shares. */
    belowMinimum: boolean;
    mean: number | null;
    /** Option or column id => share of the answers, 0 to 1. */
    shares: Record<string, number>;
}

export interface TrendSeries {
    label: string;
    points: TrendPoint[];
}

export interface QuestionTrend {
    key: string;
    title: string;
    type: string;
    measure: TrendMeasure;
    min: number | null;
    max: number | null;
    periods: { key: string; label: string }[];
    options: { id: string; label: string }[];
    series: TrendSeries[];
}

export interface TemplateTrends {
    /** False while the surveys of the cut do not reach the minimum together. */
    visible: boolean;
    surveys: number;
    responses: number;
    questions: QuestionTrend[];
}

export interface TrendFilters {
    type: string | null;
    system: string | null;
    from: string | null;
    to: string | null;
    period: TrendPeriod;
    compare: TrendComparison;
}

export interface TrendsPageProps {
    templates: { id: string; name: string }[];
    templateId: string | null;
    filters: TrendFilters;
    options: {
        subjectTypes: { value: string; label: string }[];
        gameSystems: string[];
    };
    trends: TemplateTrends | null;
    minimum: number;
    url: string;
}
