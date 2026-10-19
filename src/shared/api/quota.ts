import apiFetch from '@wordpress/api-fetch';
import { queryOptions } from '@tanstack/react-query';

const QUOTA_STALE_MS = 60_000;

export type Quota = {
    percentAvailable: number;
    windowEndsAt: string | null;
    enforced: boolean;
};

export const getQuota = async (): Promise<Quota> =>
    await apiFetch({ path: '/translatewp/v1/admin/quota', method: 'GET' }) as Quota;

export const getQuotaQueryOptions = () => queryOptions({
    queryKey: ['quota'],
    queryFn: () => getQuota(),
    staleTime: QUOTA_STALE_MS,
    refetchOnWindowFocus: false,
});
