import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

type ScheduledTasksLateNoticeProps = {
    late: boolean;
    onSyncNow: VoidFunction;
    isSyncing: boolean;
};

export const ScheduledTasksLateNotice = ({ late, onSyncNow, isSyncing }: ScheduledTasksLateNoticeProps) =>
{
    if (!late)
    {
        return null;
    }

    return (
        <div
            role="status"
            className="flex flex-col gap-2 border border-translatewp-warning/40 bg-translatewp-warning/10 rounded-lg p-3"
        >
            <div className="flex flex-col gap-1">
                <p className="text-sm font-semibold text-translatewp-neutral m-0">
                    {__('Scheduled tasks are running late on this site.', 'translate')}
                </p>
                <p className="text-sm text-translatewp-muted m-0">
                    {__('Translations are being indexed as pages are viewed. Indexing keeps progressing this way, just more slowly than usual.', 'translate')}
                </p>
            </div>
            <div className="flex items-center gap-3">
                <Button variant="secondary" onClick={onSyncNow} isBusy={isSyncing} disabled={isSyncing}>
                    {__('Sync now', 'translate')}
                </Button>
            </div>
        </div>
    );
};
