/**
 * Shapes of the organizers' pages: OrganizedSurveysQueryInterface on the server.
 */
export type OrganizedSurveyStatus =
    'to_send' | 'scheduled' | 'pending_review' | 'rejected' | 'open' | 'closed';

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
    /** Only while it never opened. */
    dismissUrl: string | null;
}

export interface ManageSubjectProps {
    subject: {
        title: string;
        typeLabel: string;
        url: string | null;
    };
    surveys: OrganizedSurvey[];
}
