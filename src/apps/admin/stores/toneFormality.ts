import type { TranslationsStyle } from '@/apps/admin/stores/settingsStore';
import { TONE_FORMALITY } from '@/shared/lib/style/toneFormality';

/** A change that picks a tone with a level, and leaves the formality as it was, moves the slider to that level. */
export const withToneFormality = (previous: TranslationsStyle, next: TranslationsStyle): TranslationsStyle =>
{
    const level = TONE_FORMALITY[next.tone];
    if (level === undefined || next.tone === previous.tone || next.formality !== previous.formality) {
        return next;
    }

    return { ...next, formality: level };
};
