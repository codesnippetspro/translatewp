import type { TranslationsStyle } from '@/apps/admin/stores/settingsStore';

/** The Formality slider's level for each tone preset that has one. */
export const TONE_FORMALITY: Record<string, number> = {
    casual: 2,
    friendly: 4,
    professional: 7,
    formal: 9,
};

/** A change that picks a tone with a level, and leaves the formality as it was, moves the slider to that level. */
export const withToneFormality = (previous: TranslationsStyle, next: TranslationsStyle): TranslationsStyle =>
{
    const level = TONE_FORMALITY[next.tone];
    if (level === undefined || next.tone === previous.tone || next.formality !== previous.formality) {
        return next;
    }

    return { ...next, formality: level };
};
