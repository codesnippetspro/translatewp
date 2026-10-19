import { __, _n, sprintf } from '@wordpress/i18n';

import type { ExtractionProgress, IndexPhase, PageCountUnit, ScanProgress } from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { getLanguageNameFromLocale } from '@/shared/lib/languages/languageName';

function formatLanguageList(locales: string[]): string
{
    const names = locales.map(locale => getLanguageNameFromLocale(locale) ?? locale);

    return new Intl.ListFormat(undefined, { style: 'long', type: 'conjunction' }).format(names);
}

export function indexProgressMessage(
    phase: IndexPhase,
    progress: ExtractionProgress,
    totalPages: number,
    activeLocales: string[],
    unit: PageCountUnit = 'pages',
): string
{
    if (phase === 'fetching')
    {
        const fetched = Math.min(progress.processed + progress.failed, totalPages);

        if (unit === 'rows')
        {
            return sprintf(
                /* translators: 1: rows fetched so far, 2: total rows to fetch. */
                _n('Fetching %1$d of %2$d row', 'Fetching %1$d of %2$d rows', totalPages, 'translate'),
                fetched,
                totalPages,
            );
        }

        return sprintf(
            /* translators: 1: pages fetched so far, 2: total pages to fetch. */
            _n('Fetching %1$d of %2$d page', 'Fetching %1$d of %2$d pages', totalPages, 'translate'),
            fetched,
            totalPages,
        );
    }

    if (phase === 'translating')
    {
        return sprintf(
            /* translators: %d: number of pages being translated. */
            _n('Translating %d page', 'Translating %d pages', progress.processed, 'translate'),
            progress.processed,
        );
    }

    // The pages were read and sent; the languages table says when each
    // translation is done, so this line never claims readiness.
    const readyMessage = progress.processed > 0
        ? sprintf(
            /* translators: 1: number of pages sent, 2: list of target language names. */
            _n('Sent %1$d page for translation in %2$s.', 'Sent %1$d pages for translation in %2$s.', progress.processed, 'translate'),
            progress.processed,
            formatLanguageList(activeLocales),
        )
        : __('Nothing new to translate.', 'translate');

    if (progress.failed === 0)
    {
        return readyMessage;
    }

    const failedMessage = sprintf(
        /* translators: %d: number of pages that could not be fetched. */
        _n('%d page could not be fetched.', '%d pages could not be fetched.', progress.failed, 'translate'),
        progress.failed,
    );

    return `${readyMessage} ${failedMessage}`;
}

/**
 * The line above the bar while the scan runs. Before the first count arrives
 * the total is unknown and the bar is indeterminate ("Finding pages"); once
 * the server reports a total it reads "Scanning pages X of Y" and names the
 * page being scanned, title first, path as the fallback. When the scan is
 * finished the done line says so with the page count.
 */
export function scanProgressMessage(scan: ScanProgress, finished: boolean): string
{
    if (finished)
    {
        return sprintf(
            /* translators: %d: number of pages read. */
            _n('Done. Read %d page.', 'Done. Read %d pages.', scan.pagesDone, 'translate'),
            scan.pagesDone,
        );
    }

    if (scan.pagesTotal <= 0)
    {
        return __('Finding pages…', 'translate');
    }

    const message = sprintf(
        /* translators: 1: pages scanned so far, 2: total pages to scan. */
        __('Scanning pages %1$d of %2$d', 'translate'),
        Math.min(scan.pagesDone + (scan.currentPage ? 1 : 0), scan.pagesTotal),
        scan.pagesTotal,
    );
    if (!scan.currentPage)
    {
        return message;
    }

    const named = scan.currentPage.title.trim() || scan.currentPage.path.trim();
    return named ? `${message} · ${named}` : message;
}

export function indexFailureMessage(failedCount: number): string
{
    return sprintf(
        /* translators: %d: number of pages that could not be read. */
        _n(
            '%d page could not be read from this browser. Open it in this browser to confirm it loads, then run Read my site again.',
            '%d pages could not be read from this browser. Open them in this browser to confirm they load, then run Read my site again.',
            failedCount,
            'translate',
        ),
        failedCount,
    );
}

/** "2 min 05 s" style elapsed time for a running index so a slow host never looks stuck. */
export function elapsedLabel(elapsedSeconds: number): string
{
    const minutes = Math.floor(elapsedSeconds / 60);
    const seconds = elapsedSeconds % 60;
    if (minutes === 0)
    {
        /* translators: %d: seconds elapsed. */
        return sprintf(__('%d s', 'translate'), seconds);
    }

    /* translators: 1: minutes elapsed, 2: seconds elapsed, two digits. */
    return sprintf(__('%1$d min %2$s s', 'translate'), minutes, String(seconds).padStart(2, '0'));
}

/**
 * The plain sentence for a page's failure code. Codes come from the queue
 * (transport and provider codes kept key-free); anything unknown
 * shows as a generic line so a raw code never reaches the screen.
 */
export function indexFailureReason(code: string | null | undefined): string
{
    switch (code)
    {
        case 'pro_required':
            return __('This model needs the Pro addon. Pick another model or install Pro, then retranslate.', 'translate');
        case 'byok_invalid_key':
            return __('The provider rejected your API key. Check the key in AI providers, then retranslate.', 'translate');
        case 'byok_model_unsupported':
            return __('The provider does not offer this model. Pick another model, then retranslate.', 'translate');
        case 'byok_provider_error':
            return __('The provider returned an error. Try again in a few minutes.', 'translate');
        case 'job_create_failed':
            return __('The translation service could not open a job. Check the connection on the Dashboard, then retranslate.', 'translate');
        case 'batch_push_failed':
        case 'http_request_failed':
            return __('The site could not send this page to the translation service. Check that the site can make outgoing requests, then retranslate.', 'translate');
        case 'invalid_stored_payload':
            return __('The stored copy of this page was unreadable. Retranslate to read it again.', 'translate');
        case 'quota_exceeded':
        case 'plan_limit':
            return __('The plan\'s translation quota is used up for this period.', 'translate');
        case 'own_providers_missing':
            return __('The plan\'s translation quota is used up and none of your own providers has a usable key. Check Settings > AI models, then retranslate.', 'translate');
        case 'rate_limited':
            return __('The translation service asked the site to slow down. It retries on its own.', 'translate');
        case 'timeout':
        case 'request_timeout':
            return __('The translation service did not answer in time. It retries on its own.', 'translate');
        case '':
        case null:
        case undefined:
            return __('No reason was recorded.', 'translate');
        default:
            /* translators: %s: the failure code. */
            return sprintf(__('The translation failed (%s). Retranslate to try again.', 'translate'), code);
    }
}
