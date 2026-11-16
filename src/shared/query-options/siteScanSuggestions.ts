import { queryOptions } from '@tanstack/react-query';
import { getScanContextStatus } from '@/shared/api/setup';

export const siteScanSuggestionsQueryOptions = queryOptions({
    queryKey: ['siteScanSuggestions'],
    queryFn: () => getScanContextStatus({ readOnly: true }),
});
