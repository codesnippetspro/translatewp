export function hasTranslationLetters(text: string): boolean
{
    return /\p{L}/u.test(text);
}
