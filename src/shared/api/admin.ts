import apiFetch from '@wordpress/api-fetch';

import type { CandidateAction, CandidateStatus, LexiconCandidate, LexiconScan } from '@/shared/lib/lexiconCandidates';

import type { FeedbackResponse, JobEvent, JobEventsResponse } from '@/shared/lib/jobEvents';
import { normalizeJobEventKind } from '@/shared/lib/jobEvents';

export const getAdminData = async (option: string) =>
{
    return await apiFetch({
        path: `/translatewp/v1/admin/get-data?option=${encodeURIComponent(option)}`,
        method: 'GET'
    });
}

export const updateAdminData = async (option: string, data: any, revision?: number | null) =>
{
    return await apiFetch({
        path: `/translatewp/v1/admin/update-data`,
        method: 'POST',
        data: {
            option,
            data: data,
            ...(typeof revision === 'number' ? { revision } : {}),
        }
    }) as { success: boolean; revision?: number };
}

export const getSettingsRevision = async (): Promise<{ revision: number }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/settings-revision',
        method: 'GET',
    }) as { revision: number };
}

/** The site's own figures from the index: translated pages and translation languages. */
export type RetranslationEstimate = { pages: number; languages: number };

export const getRetranslationEstimate = async (): Promise<RetranslationEstimate> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/retranslation-estimate',
        method: 'GET',
    }) as RetranslationEstimate;
}

export type DashboardMetrics = {
    translationsToday: number;
    cacheHitsToday: number;
    cacheHitRatio: number;
    languageUsage: { locale: string; name: string; translations: number; share: number; barColor: string }[];
    recentContent: { page: string; locale?: string; language: string; href: string }[];
    recentLexicon: { term: string; added: string; href: string }[];
    anchorMissing?: string[];
};

export const getDashboardMetrics = async (): Promise<DashboardMetrics> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/dashboard-metrics',
        method: 'GET'
    }) as DashboardMetrics;
}

export const clearSiteCache = async (): Promise<{ success: boolean; deleted: number }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/clear-cache',
        method: 'POST',
    }) as { success: boolean; deleted: number };
}

export type { FeedbackResponse, JobEvent, JobEventKind, JobEventsResponse } from '@/shared/lib/jobEvents';

export const getJobEvents = async (params?: { limit?: number; before?: string }): Promise<JobEventsResponse> =>
{
    const searchParams = new URLSearchParams();
    if (params?.limit) {
        searchParams.set('limit', String(params.limit));
    }
    if (params?.before) {
        searchParams.set('before', params.before);
    }

    const query = searchParams.toString();
    const path = query
        ? `/translatewp/v1/admin/job-events?${query}`
        : '/translatewp/v1/admin/job-events';

    const response = await apiFetch({
        path,
        method: 'GET',
    }) as JobEventsResponse;

    return {
        events: (response.events ?? []).map((event): JobEvent => ({
            ...event,
            kind: normalizeJobEventKind(event.kind),
            resolved: event.resolved === true,
        })),
    };
}

export const submitPluginFeedback = async (message: string, page?: string): Promise<FeedbackResponse> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/feedback',
        method: 'POST',
        data: {
            message,
            page: page ?? window.location.pathname + window.location.search,
        },
    }) as FeedbackResponse;
}

export const getDebugInfo = async (): Promise<{ text: string }> =>
{
    return await apiFetch({ path: '/translatewp/v1/admin/debug-info', method: 'GET' }) as { text: string };
}

export type SupportTicketResult = { sent: boolean; address: string; subject: string };

export const openSupportTicket = async (name: string, message: string, email: string): Promise<SupportTicketResult> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/support-ticket',
        method: 'POST',
        data: { name, message, email },
    }) as SupportTicketResult;
}

export type ApplyStaleResult = { success: boolean; requeued: number };

/** Queue the pages translated under an older lexicon for a retranslation. */
export const applyLexiconNow = async (): Promise<ApplyStaleResult> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/apply-lexicon-now',
        method: 'POST',
    }) as ApplyStaleResult;
}

/** Queue the pages translated under older date, time and unit settings. */
export const applyLocalizerNow = async (): Promise<ApplyStaleResult> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/apply-localizer-now',
        method: 'POST',
    }) as ApplyStaleResult;
}

/** Finished pages per language that wait for a retranslation, per group. */
export type StaleTranslations = {
    lexicon: Record<string, number>;
    localizer: Record<string, number>;
};

export const getStaleTranslations = async (): Promise<StaleTranslations> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/stale-translations',
        method: 'GET',
    }) as StaleTranslations;
}

export const restartSetupWizard = async (): Promise<{ success: boolean }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/management/restart-setup',
        method: 'POST',
    }) as { success: boolean };
}

export const dismissFinishSetup = async (): Promise<{ success: boolean }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/management/dismiss-finish-setup',
        method: 'POST',
    }) as { success: boolean };
}

export const dismissProWelcome = async (): Promise<{ success: boolean }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/management/dismiss-pro-welcome',
        method: 'POST',
    }) as { success: boolean };
}

export const resetAllPluginData = async (): Promise<{ success: boolean }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/management/reset-data',
        method: 'POST',
    }) as { success: boolean };
}

export type TranslationManager = { id: number; name: string; email: string; role: string; granted: boolean };

/** The users who can edit pages, and whether each may manage this site's translations. */
/** Each capability, as the role map stores it, with true or false per role. */
export type RolePermissions = Partial<Record<'edit_translations' | 'retranslate' | 'manage_settings' | 'manage_lexicon', Record<string, boolean>>>;

export type TranslationManagersCard = {
    users: TranslationManager[];
    roles: RolePermissions;
    roleList: Array<{ slug: string; name: string }>;
    /** False while the network locks the role map. */
    editable: boolean;
    administratorName?: string;
};

export const getTranslationManagers = async (): Promise<TranslationManagersCard> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/translation-managers',
        method: 'GET',
    }) as TranslationManagersCard;
}

export const saveTranslationManagers = async (granted: number[]): Promise<TranslationManagersCard> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/translation-managers',
        method: 'POST',
        data: { granted },
    }) as TranslationManagersCard;
}

export const saveRolePermissions = async (roles: RolePermissions): Promise<TranslationManagersCard> =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/translation-managers',
        method: 'POST',
        data: { roles },
    }) as TranslationManagersCard;
}

export type IndexPageStatus = 'indexed' | 'pending' | 'failed' | 'retranslating';
export type IndexQueueStatus = 'pending' | 'claimed' | 'done' | 'dead';

// The store state of the page's own title string for this language: a
// server-only segment the visitor path never translates, so a body can read as
// indexed while the title is still pending or never caught up.
export type IndexTitleState = 'stored' | 'pending' | 'missing';

export type IndexPageLanguage = {
    id: number;
    status: IndexPageStatus;
    queueStatus: IndexQueueStatus;
    segments: number;
    reason: string;
    updated: number;
    title: IndexTitleState;
    /** When a language still on its way started (unix seconds); 0 while it waits in the queue. */
    translatingSince?: number;
};

export type IndexPageEntry = {
    objectType: string;
    objectId: number;
    url: string;
    title: string;
    /** False for a page only a visitor opened; indexing does not cover it. */
    indexed?: boolean;
    languages: Record<string, IndexPageLanguage>;
};

export type IndexPagesResponse = {
    pages: IndexPageEntry[];
    totals: { rows: number; segments: number };
};

export const getIndexPages = async (): Promise<IndexPagesResponse> =>
{
    return await apiFetch({
        path: '/translatewp/v1/indexing/pages',
        method: 'GET',
    }) as IndexPagesResponse;
}

export type RetranslatePageInput = {
    objectType: string;
    objectId: number;
    url: string;
    // Omitted to retranslate the page in every active target language.
    language?: string;
};

export const retranslatePage = async (input: RetranslatePageInput): Promise<{ requeued: number }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/indexing/retranslate-page',
        method: 'POST',
        data: input,
    }) as { requeued: number };
}

export const retranslateEverything = async (confirm: string): Promise<{ requeued: number }> =>
{
    return await apiFetch({
        path: '/translatewp/v1/indexing/retranslate-all',
        method: 'POST',
        data: { confirm },
    }) as { requeued: number };
}

// Lexicon candidate discovery: the site's scans and proposals.
export const startLexiconScan = async (): Promise<{ scan: LexiconScan; started: boolean }> =>
{
    return await apiFetch({ path: '/translatewp/v1/admin/lexicon-candidates/scan', method: 'POST' });
};

export const getLexiconScan = async (): Promise<{ scan: LexiconScan | null }> =>
{
    return await apiFetch({ path: '/translatewp/v1/admin/lexicon-candidates/scan', method: 'GET' });
};

export const getLexiconCandidates = async (status?: CandidateStatus): Promise<{ candidates: LexiconCandidate[] }> =>
{
    const query = status ? `?status=${encodeURIComponent(status)}` : '';
    return await apiFetch({ path: `/translatewp/v1/admin/lexicon-candidates${query}`, method: 'GET' });
};

export const actOnLexiconCandidate = async (id: string, action: CandidateAction, language?: string): Promise<{ candidate: LexiconCandidate }> =>
{
    return await apiFetch({ path: '/translatewp/v1/admin/lexicon-candidates/action', method: 'POST', data: { id, action, ...(language ? { language } : {}) } });
};
