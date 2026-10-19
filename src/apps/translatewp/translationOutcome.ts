import type { TranslationGroupFailure } from '@/shared/lib/translations/Translator';

export type TranslationOutcome = 'success' | 'pending' | 'partial' | 'failure';

export const getTranslationOutcome = ({
    failures,
    totalGroups,
}: {
    failures: TranslationGroupFailure[];
    totalGroups: number;
}): TranslationOutcome =>
{
    const rejectedGroups = failures.filter(({ error }) => error !== 'translation_pending');
    if (rejectedGroups.length === 0) return failures.length === 0 ? 'success' : 'pending';
    if (totalGroups > 0 && rejectedGroups.length >= totalGroups) return 'failure';
    return 'partial';
};
