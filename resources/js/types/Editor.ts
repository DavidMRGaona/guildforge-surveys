import type { SurveyDefinition } from './Respond';

/** Props of the organizer's editor (OrganizerSurveyEditorController::edit()). */
export interface EditSurveyProps {
    survey: {
        id: string;
        kind: 'form' | 'poll';
        title: string;
        description: string | null;
        anonymous: boolean;
        durationDays: number | null;
        /** Wall time in the display zone, as a datetime-local input takes it. */
        closesAt: string | null;
        reminderHoursBefore: number | null;
        liveResults: boolean;
        visibleToRespondents: boolean;
        opened: boolean;
        pendingReview: boolean;
        rejectionReason: string | null;
        sendable: boolean;
    };
    definition: SurveyDefinition;
    subject: { title: string };
    /** Someone answered: only help texts and the closing date may change. */
    locked: boolean;
    updateUrl: string;
    backUrl: string;
}

/** How much of the content the editor lets change. */
export interface EditorLocks {
    /** Questions, options and sections stay as they are. */
    structure: boolean;
    /** Help texts too (while the survey waits for review). */
    help: boolean;
    poll: boolean;
}
