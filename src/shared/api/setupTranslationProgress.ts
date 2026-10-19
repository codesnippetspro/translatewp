import { getIndexPages, type IndexPagesResponse } from '@/shared/api/admin';

export type SetupIndexStatus = {
    languages: Record<string, { complete: boolean; ready?: number; readyTotal?: number; total?: number }>;
    read?: { pages: number };
    /** Set when the last readiness refresh failed; the counts are the last confirmed ones. */
    unconfirmed?: boolean;
};

export type SetupTranslationProgress = {
    status: SetupIndexStatus;
    pages: IndexPagesResponse;
};

export const getSetupTranslationProgress = async (): Promise<SetupTranslationProgress> =>
{
    return { status: { languages: {} }, pages: await getIndexPages() };
};
