/**
 * Respond TypeScript types for the Surveys module.
 * Keys match RespondResponseDTO::toArray().
 */

export interface Respond {
    id: string;
    name: string;
    created_at: string;
    updated_at: string;
}

export interface RespondFormData {
    name: string;
}

// Props RespondController (module:make-controller --resource) sends
export interface RespondListProps {
    responds: Respond[];
}

export interface RespondShowProps {
    respond: Respond;
}
