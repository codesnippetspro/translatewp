import apiFetch from '@wordpress/api-fetch';
import type { DashboardMetrics, IndexPagesResponse } from '@/shared/api/admin';
import type { IndexStatus } from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import type { TranslationSubscription } from '@/shared/lib/translations/TranslationEventClient';

export type TranslationReadinessResponse = {
    cursor: number;
    language: string;
    hashes: string[];
    subscription: TranslationSubscription | null;
    pages: IndexPagesResponse;
    status: IndexStatus;
    translations: Pick<DashboardMetrics, 'translationsToday' | 'languageUsage' | 'recentContent'>;
};

export const refreshTranslationReadiness = (cursor: number, signal: AbortSignal): Promise<TranslationReadinessResponse> =>
    apiFetch({ path: '/translatewp/v1/indexing/readiness', method: 'POST', data: { cursor }, signal });
