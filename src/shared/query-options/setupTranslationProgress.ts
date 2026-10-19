import { queryOptions } from '@tanstack/react-query';

import { getSetupTranslationProgress } from '@/shared/api/setupTranslationProgress';
import { hasOpenTranslationWork } from '@/shared/lib/setupTranslationProgress';

export const setupTranslationProgressQueryOptions = (languages: string[]) => queryOptions({
    queryKey: ['setupTranslationProgress', languages],
    queryFn: getSetupTranslationProgress,
    enabled: languages.length > 0,
    staleTime: 0,
    gcTime: 0,
    networkMode: 'always',
    refetchInterval: query => hasOpenTranslationWork(query.state.data, languages) ? 1000 : false,
});
