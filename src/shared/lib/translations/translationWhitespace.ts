const TRIM_PATTERN = /^\p{Z}+|\p{Z}+$/gu;

export function trimTranslationWhitespace(value: string): string
{
    return value.replace(TRIM_PATTERN, '');
}

export function hasTranslatableWhitespaceText(value: string): boolean
{
    return trimTranslationWhitespace(value).length > 0;
}
