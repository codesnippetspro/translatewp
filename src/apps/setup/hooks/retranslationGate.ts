import { __ } from '@wordpress/i18n';

import { getRetranslationEstimate } from '@/shared/api/admin';
import { getQuota } from '@/shared/api/quota';
import { quotaCostSentence, retranslatedAgainSentence } from '@/shared/lib/retranslationScope';
import { useConfirmStore } from '@/apps/setup/stores/confirmStore';

/**
 * A wizard opened on a site that already finished setup (Restart setup) can
 * change the context, style and tone the translations are made with. Such a
 * change marks every translation stale, so it states what is translated
 * again before it runs, with the same figures and words the Settings page uses.
 */
export const isRestartedSetup = (): boolean => window.setupData?.step === 'done';

export const contextChangedSinceLoad = (context: string): boolean =>
    context.trim() !== (window.setupData?.context ?? '').trim();

export const styleChangedSinceLoad = (style: string, tone: string): boolean =>
    style !== (window.setupData?.style ?? style) || tone !== (window.setupData?.tone ?? tone);

const LEADS = {
    scan: () => __('Reading the site again replaces the context, style and tone, and the site is translated again with them.', 'translate'),
    finish: () => __('Your new context is used from now on, and the site is translated again with it.', 'translate'),
    style: () => __('Your new style or tone is used from now on, and the site is translated again with it.', 'translate'),
};

const CONFIRM_LABELS = {
    scan: () => __('Read again and retranslate', 'translate'),
    finish: () => __('Finish and retranslate', 'translate'),
    style: () => __('Save and retranslate', 'translate'),
};

export const confirmRetranslation = async (action: keyof typeof LEADS): Promise<boolean> =>
{
    const request = useConfirmStore.getState().request;
    if (!request) {
        return true;
    }

    const [estimate, quota] = await Promise.all([
        getRetranslationEstimate().catch(() => null),
        getQuota().catch(() => null),
    ]);
    const lead = LEADS[action]();

    return request({
        title: __('Translate the site again', 'translate'),
        message: `${lead} ${retranslatedAgainSentence(estimate)} ${quotaCostSentence(estimate, quota)}`,
        confirmLabel: CONFIRM_LABELS[action](),
        tone: 'proceed',
    });
};
