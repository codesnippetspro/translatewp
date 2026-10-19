import { __, sprintf } from '@wordpress/i18n';

import { scanProgressMessage } from '@/apps/admin/components/Translations/Indexing/indexProgressMessage';
import type { ScanProgress } from '@/apps/admin/components/Translations/Indexing/extractionDriver';

type ScanProgressBarProps = {
    scan: ScanProgress;
    finished: boolean;
    failed: number;
    showMessage?: boolean;
    /** Everything the bar stands for has finished: it turns green. */
    succeeded?: boolean;
};

/**
 * The page-level progress bar of a running site scan. The chunk detail line
 * the caller already renders stays underneath; this bar owns the page count
 * and the name of the page being scanned. With no total yet the bar is
 * indeterminate with "Finding pages".
 */
export function ScanProgressBar({ scan, finished, failed, showMessage = true, succeeded = false }: ScanProgressBarProps)
{
    const knownTotal = scan.pagesTotal > 0;
    const fraction = knownTotal ? Math.min(1, scan.pagesDone / scan.pagesTotal) : 0;
    const fill = succeeded ? 'bg-translatewp-success' : 'bg-translatewp-primary';

    return (
        <div className="flex flex-col gap-1" role="status" aria-label={__('Site scan progress', 'translate')}>
            {showMessage && <p className="text-sm text-translatewp-muted m-0">
                {scanProgressMessage(scan, finished)}
            </p>}
            <div
                className="w-full h-2 rounded-sm bg-translatewp-border overflow-hidden"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={knownTotal ? scan.pagesTotal : undefined}
                aria-valuenow={knownTotal ? Math.min(scan.pagesDone + (scan.currentPage ? 1 : 0), scan.pagesTotal) : undefined}
            >
                {knownTotal
                    ? <div className={`h-full ${fill} transition-[width] duration-300`} style={{ width: `${Math.round(fraction * 100)}%` }} />
                    : <div className="h-full w-1/3 bg-translatewp-primary animate-pulse" />}
            </div>
            {finished && failed > 0 && (
                <p className="text-sm text-translatewp-error m-0">
                    {sprintf(
                        /* translators: %d: pages that could not be read. */
                        __('%d pages could not be read.', 'translate'),
                        failed,
                    )}
                </p>
            )}
        </div>
    );
}
