import { Button, Modal } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import type { NetworkAdminData } from '@/apps/network-admin/types';
import { ConnectOutcomeNotice } from '@/apps/network-admin/ConnectOutcomeNotice';

const Nonce = ({ value }: { value: string }) => <input type="hidden" name="_wpnonce" value={value} />;

export const NetworkConnection = ({ data }: { data: NetworkAdminData }) =>
{
    const [subsiteChooserOpen, setSubsiteChooserOpen] = useState(false);
    const [chosenHost, setChosenHost] = useState('');
    const connectableSubsites = data.subsites.filter(site => site.host !== '');

    return (
        <>
            <ConnectOutcomeNotice outcome={data.connection.outcome} form={{ url: data.actions.url, action: data.actions.connect, nonce: data.actions.nonces.connect }} />
            <p className="m-0 text-sm text-translatewp-muted!">
                {data.connection.connected
                    ? data.connection.accountLabel || __('This network is connected.', 'translate')
                    : __('Connect once to register every subsite under the same account.', 'translate')}
            </p>
            <div className="flex flex-wrap gap-2">
                <form method="post" action={data.actions.url}>
                    <input type="hidden" name="action" value={data.actions.connect} />
                    <Nonce value={data.actions.nonces.connect} />
                    {data.connection.connected && <input type="hidden" name="reconnect" value="1" />}
                    <Button variant="primary" type="submit">
                        {data.connection.connected ? __('Reconnect all sites', 'translate') : __('Connect all sites', 'translate')}
                    </Button>
                </form>
                {connectableSubsites.length > 0 && (
                    <Button variant="secondary" onClick={() => setSubsiteChooserOpen(true)}>
                        {__('Connect a subsite', 'translate')}
                    </Button>
                )}
            </div>
            {subsiteChooserOpen && (
                <Modal
                    title={__('Connect a subsite', 'translate')}
                    onRequestClose={() => setSubsiteChooserOpen(false)}
                    className="w-[420px]! max-w-[92vw]! rounded-xl!"
                >
                    <form method="post" action={data.actions.url} className="flex flex-col gap-4">
                        <input type="hidden" name="action" value={data.actions.connect} />
                        <Nonce value={data.actions.nonces.connect} />
                        <input type="hidden" name="only_hosts" value={chosenHost} />
                        <p className="m-0 text-sm text-translatewp-neutral!">
                            {__('Choose the subsite to connect. The other subsites stay as they are.', 'translate')}
                        </p>
                        <div className="flex flex-col gap-2">
                            {connectableSubsites.map(site => (
                                <label key={site.id} className="flex items-start gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name="subsite"
                                        value={site.host}
                                        checked={chosenHost === site.host}
                                        onChange={() => setChosenHost(site.host)}
                                    />
                                    <span>
                                        <strong className="block text-translatewp-neutral">{site.name}</strong>
                                        <span className="text-translatewp-muted!">{site.url}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="tertiary" onClick={() => setSubsiteChooserOpen(false)}>
                                {__('Cancel', 'translate')}
                            </Button>
                            <Button variant="primary" type="submit" disabled={chosenHost === ''}>
                                {__('Connect', 'translate')}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
};
