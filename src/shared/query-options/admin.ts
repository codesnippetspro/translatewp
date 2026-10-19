import { queryOptions } from "@tanstack/react-query";
import { getAdminData, getDashboardMetrics, getDebugInfo, getIndexPages, getJobEvents, getLexiconCandidates, getLexiconScan } from "@/shared/api/admin";
import { isScanOpen, type CandidateStatus } from "@/shared/lib/lexiconCandidates";
import { hasPendingTitles, INDEX_PAGES_PENDING_REFETCH_MS } from "@/shared/lib/indexPages";

export const getAdminDataQueryOptions = (option: string) => queryOptions({
    queryKey: ['getAdminData', option],
    queryFn: () => getAdminData(option)
});

export const getDashboardMetricsQueryOptions = () => queryOptions({
    queryKey: ['dashboardMetrics'],
    queryFn: () => getDashboardMetrics(),
    staleTime: 0,
    gcTime: 0,
});

export const getDebugInfoQueryOptions = () => queryOptions({
    queryKey: ['debugInfo'],
    queryFn: () => getDebugInfo(),
    staleTime: 0,
});

export const getJobEventsQueryOptions = (limit: number = 20) => queryOptions({
    queryKey: ['jobEvents', limit],
    queryFn: () => getJobEvents({ limit }),
    staleTime: 0,
    gcTime: 0,
});

export const getIndexPagesQueryOptions = () => queryOptions({
    queryKey: ['indexPages'],
    queryFn: () => getIndexPages(),
    staleTime: 0,
    gcTime: 0,
    refetchInterval: (query) => hasPendingTitles(query.state.data) ? INDEX_PAGES_PENDING_REFETCH_MS : false,
    // Same-origin WordPress REST call: 'online' mode would pause the fetch with
    // no request and no error whenever navigator.onLine misreports offline.
    networkMode: 'always',
});

export const getLexiconScanQueryOptions = () => queryOptions({
    queryKey: ['lexiconScan'],
    queryFn: () => getLexiconScan(),
    staleTime: 0,
    gcTime: 0,
    refetchInterval: (query) => isScanOpen(query.state.data?.scan) ? 3000 : false,
});

export const getLexiconCandidatesQueryOptions = (status?: CandidateStatus) => queryOptions({
    queryKey: ['lexiconCandidates', status ?? 'all'],
    queryFn: () => getLexiconCandidates(status),
    staleTime: 0,
    gcTime: 0,
});
