/**
 * Shapes of the organizers' pages: OrganizedSurveysQueryInterface on the server.
 */
export type OrganizedSurveyStatus =
    'prepared' | 'to_send' | 'scheduled' | 'pending_review' | 'rejected' | 'open' | 'closed';

export interface OrganizedSurvey {
    id: string;
    title: string;
    subjectTitle: string | null;
    status: OrganizedSurveyStatus;
    scheduledSendAt: string | null;
    closesAt: string | null;
    rejectionReason: string | null;
    responses: number;
    audienceSize: number;
    /** Only while it can still be sent (offered, scheduled or rejected). */
    sendUrl: string | null;
    /** Prepared before its subject ends: whether it goes out by itself then, and how much later. */
    sendOnEnd: boolean;
    sendDelayHours: number;
    /** Only while it never opened. */
    dismissUrl: string | null;
    /** Until it closes. */
    editUrl: string | null;
    /** Once it opened. */
    resultsUrl: string | null;
}

export interface ManageSubjectProps {
    subject: {
        title: string;
        typeLabel: string;
        url: string | null;
        status: 'active' | 'ended' | 'cancelled';
    };
    surveys: OrganizedSurvey[];
    /** While the subject's end survey can be prepared ahead. */
    prepareUrl: string | null;
}
