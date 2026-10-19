import { __, _n, sprintf } from '@wordpress/i18n';
import { create } from 'zustand';

import { getRetranslationEstimate } from '@/shared/api/admin';
import { getQuota } from '@/shared/api/quota';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { quotaCostSentence } from '@/shared/lib/retranslationScope';
import type { ConfirmDialogContent } from '@/shared/components/confirmDialog';

export type RequestConfirmation = (content: ConfirmDialogContent) => Promise<boolean>;

interface AdminConfirmStore {
    request: RequestConfirmation | null;
    setRequest: (request: RequestConfirmation | null) => void;
}

/**
 * The admin shell owns the one confirm dialog; an addon panel asks it for a
 * decision through this store instead of rendering a dialog of its own.
 */
export const useAdminConfirmStore = create<AdminConfirmStore>()((set) => ({
    request: null,
    setRequest: (request) => set({ request }),
}));

/**
 * Ask before a change that translates some languages again (a model or
 * provider change for them), stating the pages and the quota share first.
 * The admin shell always mounts the dialog; without it the change is not
 * made, never made silently.
 */
export const confirmLanguageRetranslation = async (languages: string[]): Promise<boolean> =>
{
    if (languages.length === 0) {
        return true;
    }
    const request = useAdminConfirmStore.getState().request;
    if (!request) {
        return false;
    }

    const [estimate, quota] = await Promise.all([
        getRetranslationEstimate().catch(() => null),
        getQuota().catch(() => null),
    ]);
    const pages = estimate?.pages ?? 0;
    const names = languages.map(locale => getLanguageNameFromLocale(locale) || locale).join(', ');
    const lead = pages > 0
        ? sprintf(
            /* translators: 1: language names, 2: number of pages. */
            _n('Changing the model for %1$s translates its %2$d page again.', 'Changing the model for %1$s translates its %2$d pages again.', pages, 'translate'),
            names,
            pages
        )
        /* translators: %s: language names. */
        : sprintf(__('Changing the model for %s translates its pages again.', 'translate'), names);

    return request({
        title: __('Translate those languages again?', 'translate'),
        message: `${lead} ${quotaCostSentence(pages > 0 ? { pages, languages: languages.length } : null, quota)}`,
        confirmLabel: __('Save and retranslate', 'translate'),
        cancelLabel: __('Maybe later', 'translate'),
        tone: 'proceed',
    });
};
