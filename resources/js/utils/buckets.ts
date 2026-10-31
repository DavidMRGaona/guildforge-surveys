import type { QuestionType } from '../types/Respond';

/**
 * The label one bar shows: "Other" for the bucket without label, a calendar day formatted in
 * UTC (so it never moves to the day before), anything else as it comes.
 */
export function bucketLabel(
    type: QuestionType,
    label: string | null,
    otherLabel: string,
    locale: string
): string {
    if (label === null) {
        return otherLabel;
    }

    if (type === 'date') {
        return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' }).format(
            new Date(`${label}T00:00:00Z`)
        );
    }

    return label;
}

export function percent(count: number, total: number): number {
    return total > 0 ? Math.round((count / total) * 100) : 0;
}
