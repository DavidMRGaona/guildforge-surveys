/**
 * Shapes of the organizers' pages: OrganizedSurveysQueryInterface on the server.
 */
export type OrganizedSurveyStatus =
    | 'draft'
    | 'prepared'
    | 'to_send'
    | 'scheduled'
    | 'pending_review'
    | 'rejected'
    | 'open'
    | 'closed';

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
    /** Only while it can still be sent (a draft, offered, scheduled or rejected). */
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
    /** Only while it is open. */
    closeUrl: string | null;
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
    /** New surveys and polls of the organizer's own; null once the subject is cancelled. */
    createUrls: { form: string; poll: string } | null;
    /** Which survey goes out when the subject ends; null when there is nothing to choose. */
    endChoice: EndSurveyChoice | null;
}

export interface EndSurveyChoiceOption {
    value: string;
    label: string;
}

export interface EndSurveyChoice {
    options: EndSurveyChoiceOption[];
    selected: string;
    /** The option that prepares a survey of the subject's own, from one of baseTemplates. */
    ownValue: string | null;
    baseTemplates: EndSurveyChoiceOption[];
    locked: boolean;
    lockedReason: string | null;
    editUrl: string | null;
    url: string;
}
