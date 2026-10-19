import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { NetworkConnectOutcome } from '@/apps/network-admin/types';

interface ConnectForm
{
    url: string;
    action: string;
    nonce: string;
}

const outcomeLine = (entry: NetworkConnectOutcome): string =>
{
    if (entry.ok) {
        return entry.reconnected
            /* translators: %s: subsite name */
            ? sprintf(__('%s: reconnected, translations kept', 'translate'), entry.name)
            /* translators: %s: subsite name */
            : sprintf(__('%s: connected', 'translate'), entry.name);
    }

    /* translators: 1: subsite name, 2: why it was not connected */
    return sprintf(__('%1$s: not connected. %2$s', 'translate'), entry.name, entry.reason);
};

export const ConnectOutcomeNotice = ({ outcome, form }: { outcome: NetworkConnectOutcome[]; form: ConnectForm }) =>
{
    if (outcome.length === 0) {
        return null;
    }

    return (
        <div data-connect-outcome className="flex flex-col gap-3 rounded-lg border border-translatewp-border bg-white p-4" role="status">
            <p className="m-0 text-sm font-semibold text-translatewp-neutral!">{__('Connection finished', 'translate')}</p>
            <ul className="m-0 flex list-none flex-col gap-2 p-0 text-sm text-translatewp-neutral!">
                {outcome.map(entry => (
                    <li key={entry.host} className="flex flex-wrap items-center gap-3">
                        <span>{outcomeLine(entry)}</span>
                        {entry.retry && (
                            <form method="post" action={form.url}>
                                <input type="hidden" name="action" value={form.action} />
                                <input type="hidden" name="_wpnonce" value={form.nonce} />
                                <input type="hidden" name="only_hosts" value={entry.host} />
                                <Button variant="secondary" type="submit" size="compact">
                                    {/* translators: %s: subsite name */}
                                    {sprintf(__('Try again for %s', 'translate'), entry.name)}
                                </Button>
                            </form>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
};
