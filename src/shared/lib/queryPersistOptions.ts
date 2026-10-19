import type { DehydrateOptions, Mutation, Query } from '@tanstack/react-query';

/**
 * The admin keeps a copy of its display lists in browser storage. The
 * connect status, the dashboard metrics, the account quota and the settings
 * the form initialises from are never stored; they always come from the site.
 */
const NEVER_PERSISTED = ['connectStatus', 'dashboardMetrics', 'getAdminData', 'quota'];

export const queryPersistDehydrateOptions: DehydrateOptions = {
    shouldDehydrateQuery: (query: Query) =>
        query.state.status === 'success'
        && !NEVER_PERSISTED.includes(String(query.queryKey[0])),
    shouldDehydrateMutation: (_mutation: Mutation) => false,
};

/**
 * The stored copy is keyed by the admin path; subsites of one origin each
 * hold their own.
 */
export const persistedQueryStorageKey = (): string => `translatewp-admin-query:${window.location.pathname}`;
