export type ExtractionOptions = {
    includeAlt: boolean;
    includeAttributes: boolean;
    // Page areas (comments, menus, widgets) whose text stays as written.
    excludedAreas?: string[];
};

export const DEFAULT_EXTRACTION_OPTIONS: ExtractionOptions = Object.freeze({
    includeAlt: true,
    includeAttributes: true,
});

/**
 * Entry types: Identifies the origin of an extracted string.
 */
export const EntryType = Object.freeze({
    TEXT: 'text',
    ALT: 'alt',
    ATTRIBUTE: 'attribute'
});

/** One of the EntryType string values: 'text' | 'alt' | 'attribute' */
export type EntryTypeValue = (typeof EntryType)[keyof typeof EntryType];

/**
 * HTML attributes that carry human-readable, translatable content.
 * `alt` is intentionally excluded here. It is handled separately via EntryType.ALT so it can be toggled independently.
 */
export const TRANSLATABLE_ATTRIBUTES = Object.freeze([
    'title',
    'placeholder',
    'aria-label',
    'aria-description',
    'aria-roledescription',
    'aria-valuetext',
    'label',
    'caption',
    'summary',
    'value',
]);

/**
 * Input types whose `value` attribute is UI chrome (button label), not user/data content.
 */
export const TRANSLATABLE_VALUE_INPUT_TYPES = Object.freeze(new Set([
    'submit',
    'button',
    'reset',
]));

/**
 * Default CSS class name that opts an element (and its descendants) out of translation. Configurable per TranslationEngine instance.
 */
export const DEFAULT_NO_TRANSLATE_CLASS = 'translatewp-no-translate';

// WordPress core renders these edit links only for signed-in editors; a visitor
// never sees them, so they are never content to translate.
export const ADMIN_ONLY_CLASSES = Object.freeze(['post-edit-link', 'comment-edit-link']);

/**
 * Tags whose text content must never be extracted.
 * These either contain code, styles or are invisible to users.
 */
export const SKIP_TAGS = Object.freeze(new Set ([
    'SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'CODE', 'KBD', 'SAMP', 'VAR', 'SVG', 'MATH'
]));