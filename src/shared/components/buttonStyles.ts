// The button language from the user portal, as shared class strings so every
// plugin-owned surface carries one set of tones, radii and states. Primary is
// the solid primary pill; secondary is the outlined neutral pill; the block
// variants fill the width of a modal or card footer. Colours keep their
// importance marker because wp-admin paints its own anchor and button colours.
const FOCUS = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-twp-primary-500';

const TRANSITION = 'transition-[background-color,transform,opacity] duration-300 ease-[var(--ease-twp)]';

// The label never wraps: whitespace-nowrap keeps the button text on one line
// and shrink-0 keeps the button its own width inside a flex row, so when space
// runs out the row wraps the whole button instead of breaking its text.
const NOWRAP = 'whitespace-nowrap shrink-0';

export const TWP_BUTTON_PRIMARY =
    `inline-flex items-center justify-center gap-1.5 ${NOWRAP} rounded-full border-0 bg-twp-primary-500! px-3.5 py-1.5 text-sm font-semibold text-white! no-underline cursor-pointer ${TRANSITION} hover:bg-twp-primary-500/90 active:scale-[0.98] ${FOCUS} disabled:opacity-60 disabled:active:scale-100`;

export const TWP_BUTTON_SECONDARY =
    `inline-flex items-center justify-center gap-1.5 ${NOWRAP} rounded-full border border-twp-neutral-200 bg-twp-neutral-0 px-3.5 py-1.5 text-sm font-semibold text-twp-neutral-800! no-underline cursor-pointer ${TRANSITION} hover:bg-twp-neutral-100 ${FOCUS} disabled:opacity-60`;

export const TWP_BUTTON_PRIMARY_BLOCK =
    `inline-flex w-full items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border-0 bg-twp-primary-500! px-5 py-3 text-sm font-semibold text-white! no-underline cursor-pointer ${TRANSITION} hover:bg-twp-primary-500/90 active:scale-[0.98] ${FOCUS} disabled:opacity-60 disabled:active:scale-100`;

export const TWP_BUTTON_GHOST =
    `inline-flex items-center justify-center gap-1.5 ${NOWRAP} rounded-lg border-0 bg-transparent px-5 py-2.5 text-sm font-medium text-twp-neutral-500! cursor-pointer ${TRANSITION} hover:bg-twp-neutral-100 ${FOCUS}`;
