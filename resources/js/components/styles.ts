/**
 * Shared looks of the module's public pages, built only from theme tokens so they follow the
 * site palette and the dark theme (the core's own utilities win over light/dark pairs).
 */

/** Visible keyboard focus, offset from the page. */
export const FOCUS_RING_CLASS =
    'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 focus-visible:ring-offset-page';

/** A block of the page: a survey, a section of settings. */
export const CARD_CLASS = 'rounded-lg border border-default bg-surface';

/** A text link in the primary colour (readable on page, surface and muted). */
export const LINK_CLASS = `rounded-sm text-sm font-medium text-primary underline-offset-2 hover:underline ${FOCUS_RING_CLASS}`;

/**
 * A link that does what a button does, as tall as the core's buttons (44 px) and full width
 * on phones, where actions stack; the button colour is the one the core darkens until white
 * text reaches 4.5:1.
 */
const ACTION_BASE_CLASS = `inline-flex min-h-11 w-full items-center justify-center rounded-lg px-4 text-sm font-medium transition-colors sm:w-auto ${FOCUS_RING_CLASS}`;

export const PRIMARY_ACTION_CLASS = `${ACTION_BASE_CLASS} bg-primary text-white hover:bg-primary-700`;

/** Its border reaches 3:1 against the surface in both themes (text-muted is computed for it). */
export const SECONDARY_ACTION_CLASS = `${ACTION_BASE_CLASS} border border-base-muted bg-surface text-base-primary hover:bg-muted`;

/** A link next to buttons that goes somewhere instead of changing something: text, button height. */
export const LINK_ACTION_CLASS = `inline-flex min-h-11 w-full items-center justify-center rounded-lg px-3 text-sm font-medium text-primary underline-offset-2 hover:underline sm:w-auto ${FOCUS_RING_CLASS}`;

/** Small status label: the theme text over a pale status colour reads in both themes. */
export const BADGE_CLASS = 'inline-block rounded-full px-2 py-0.5 text-xs font-medium';

/**
 * A text field with the look of the core's form controls (FormSelect, FormNumberInput); a
 * field marked aria-invalid gets the error border. The border colour is written as its
 * variable: the core's own .border-default sits in a higher layer than this module's sheet
 * and would beat the aria-invalid and focus borders.
 */
export const FIELD_CLASS =
    'block w-full rounded-lg border border-(--color-border) bg-surface px-4 py-2.5 text-base-primary transition-colors hover:border-strong focus:border-primary-500 focus:ring-2 focus:ring-primary-500 focus:outline-none disabled:cursor-not-allowed disabled:bg-muted aria-invalid:border-error aria-invalid:focus:ring-error';

/** A square button with a symbol (move, remove): 44 px on phones, smaller with a pointer. */
export const ICON_BUTTON_CLASS = `inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-base-secondary transition-colors hover:bg-base-primary/10 hover:text-base-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent sm:h-9 sm:w-9 ${FOCUS_RING_CLASS}`;

/** A button that adds an item (a question, a section, an option). */
export const ADD_BUTTON_CLASS = `inline-flex min-h-11 items-center justify-center gap-1.5 rounded-lg border border-base-muted bg-surface px-4 text-sm font-medium text-base-primary transition-colors hover:bg-muted ${FOCUS_RING_CLASS}`;

/** A notice block: the theme text over a pale status colour, readable in both themes. */
export const NOTICE_CLASS = 'rounded-lg p-4 text-sm break-words text-base-primary';
