import type { IndexPagesResponse } from '@/shared/api/admin';

export const INDEX_PAGES_PENDING_REFETCH_MS = 5000;

export const hasPendingTitles = (data: IndexPagesResponse | undefined): boolean =>
    (data?.pages ?? []).some(page => Object.values(page.languages).some(language => 'pending' === language.title));
