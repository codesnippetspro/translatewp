import { __, _n, sprintf } from '@wordpress/i18n';

import type { RetranslationEstimate } from '@/shared/api/admin';
import type { Quota } from '@/shared/api/quota';

/**
 * What a retranslation covers, in the site's own figures from the index:
 * "N pages in M translation languages". Null while the index has
 * no translated page, so the caller falls back to words without figures.
 */
export const retranslationScope = (estimate: RetranslationEstimate | null): string | null =>
{
    if (!estimate || estimate.pages <= 0 || estimate.languages <= 0) {
        return null;
    }

    const pages = sprintf(
        /* translators: %s: number of pages. */
        _n('%s page', '%s pages', estimate.pages, 'translate'),
        estimate.pages.toLocaleString()
    );
    const languages = sprintf(
        /* translators: %s: number of translation languages. */
        _n('%s translation language', '%s translation languages', estimate.languages, 'translate'),
        estimate.languages.toLocaleString()
    );

    /* translators: 1: number of pages, 2: number of translation languages. */
    return sprintf(__('%1$s in %2$s', 'translate'), pages, languages);
};

/** The sentence after the lead: what is translated again, and when. */
export const retranslatedAgainSentence = (estimate: RetranslationEstimate | null): string =>
{
    const scope = retranslationScope(estimate);

    return scope && estimate
        ? sprintf(
            /* translators: %s: pages and translation languages, e.g. "12 pages in 3 translation languages". */
            _n(
                'The %s on this site is translated again on its next visit.',
                'The %s on this site are translated again on their next visit.',
                estimate.pages,
                'translate'
            ),
            scope
        )
        : __('Pages are translated again on their next visit.', 'translate');
};

/** What the run costs: the page translations it counts against the quota, and what is left. */
export const quotaCostSentence = (estimate: RetranslationEstimate | null, quota: Quota | null): string =>
{
    const translations = estimate ? estimate.pages * estimate.languages : 0;
    const cost = translations > 0
        ? sprintf(
            /* translators: %s: number of page translations. */
            _n('This counts %s page translation towards your quota.', 'This counts %s page translations towards your quota.', translations, 'translate'),
            translations.toLocaleString()
        )
        : __('The pages translated again count towards your quota.', 'translate');
    const left = quota && Number.isFinite(quota.percentAvailable)
        ? ' ' + sprintf(
            /* translators: %s: percent of the quota still available. */
            __('%s%% of it is available right now.', 'translate'),
            String(quota.percentAvailable)
        )
        : '';

    return cost + left;
};

/** The confirmation a translation-affecting settings save opens. */
export const retranslateOnSaveMessage = (estimate: RetranslationEstimate | null, quota: Quota | null = null): string =>
    `${__('These changes translate the site again with the new settings.', 'translate')} ${retranslatedAgainSentence(estimate)} ${quotaCostSentence(estimate, quota)}`;
