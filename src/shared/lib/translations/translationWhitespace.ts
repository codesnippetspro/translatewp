import { hasTranslationLetters } from '@/shared/lib/translations/hasTranslationLetters';

const TRIM_PATTERN = /^\p{Z}+|\p{Z}+$/gu;

export function trimTranslationWhitespace(value: string): string
{
    return value.replace(TRIM_PATTERN, '');
}

export function hasTranslatableWhitespaceText(value: string): boolean
{
    return trimTranslationWhitespace(value).length > 0;
}

// A delimiter printed in the same text node as a link's words (a breadcrumb's
// " / Product") stays as written and out of the text sent for translation, so
// the words read as a label, not as a path. Arrows only lead, so a "Next »"
// keeps its arrow in the text.
const SEPARATOR_EDGES = /^([\s\p{Z}]*[/|›»·•\u{2013}\u{2014}][\s\p{Z}]+)?(.*?)([\s\p{Z}]+[/|·•\u{2013}\u{2014}][\s\p{Z}]*)?$/su;

export type SeparatorEdges = { prefix: string; core: string; suffix: string };

export function splitSeparatorEdges(value: string): SeparatorEdges
{
    const match = SEPARATOR_EDGES.exec(value);
    if (!match || !hasTranslationLetters(match[2])) return { prefix: '', core: value, suffix: '' };

    return { prefix: match[1] ?? '', core: match[2], suffix: match[3] ?? '' };
}

/** The translation of a value's words with the value's own separator edges around it. */
export function withSeparatorEdges(value: string, translatedByCore: Map<string, string>): string | undefined
{
    const { prefix, core, suffix } = splitSeparatorEdges(value);
    const translated = translatedByCore.get(core);

    return undefined === translated ? undefined : `${prefix}${translated}${suffix}`;
}
