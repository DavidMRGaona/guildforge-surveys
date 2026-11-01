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
