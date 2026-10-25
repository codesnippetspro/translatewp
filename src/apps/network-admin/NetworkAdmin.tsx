import { __ } from '@wordpress/i18n';
import { ArrowRight, CircleCheck } from 'lucide-react';

import type { NetworkAdminData } from '@/apps/network-admin/types';
import type { ConnectStatus } from '@/shared/api/connect';
import { Header } from '@/apps/admin/components/Header';
import { PageHeader, PageTabs, type PageTab } from '@/apps/admin/components/PageLayout';
import { useTabHash } from '@/apps/admin/hooks/useTabHash';
import { Section } from '@/shared/components/adminSurface';
import { Table, TableCell, TableHeaderRow, TableRow } from '@/shared/components/form/table';
import { SettingsScopeSection } from '@/apps/network-admin/SettingsScopeSection';
import { NetworkConnection } from '@/apps/network-admin/NetworkConnection';
import { NetworkOverview } from '@/apps/network-admin/NetworkOverview';

const NETWORK_TABS = ['overview', 'subsites', 'settings'];

export const NetworkAdmin = ({ data }: { data: NetworkAdminData }) =>
{
    const { activeTabName, onSelectTab } = useTabHash(NETWORK_TABS, 'overview');

    const status = { connected: data.connection.connected, accountLabel: data.connection.accountLabel, pro: data.connection.pro } as ConnectStatus;

    return (
        <div className="translatewp-app-root w-full flex flex-col gap-6 pb-24">
            <Header status={status} />
            <div className="px-4 md:px-6">
            <div className="bg-white rounded-lg">
            <PageHeader
                title={__('Network settings', 'translate')}
                description={__('Manage TranslateWP across every subsite in this network.', 'translate')}
            />
            <PageTabs
                tabs={[
                    {
                        name: 'overview',
                        title: __('Overview', 'translate'),
                        content: <NetworkOverview data={data} subsitesHref="#subsites" />,
                    },
                    {
                        name: 'subsites',
                        title: __('Sites', 'translate'),
                        content: (
                            <Section
                                title={__('Sites', 'translate')}
                                description={__('Connection, setup, languages, and direct controls for every site.', 'translate')}
                            >
                                <div className="overflow-x-auto rounded-lg bg-white">
                                    <Table className="min-w-240">
                                        <TableHeaderRow columnsClassName="grid-cols-[2fr_0.6fr_1.2fr_1.1fr_1.1fr_1.5fr]">
                                            <TableCell variant="header">{__('Site', 'translate')}</TableCell>
                                            <TableCell variant="header">{__('ID', 'translate')}</TableCell>
                                            <TableCell variant="header">{__('Connection', 'translate')}</TableCell>
                                            <TableCell variant="header">{__('Setup', 'translate')}</TableCell>
                                            <TableCell variant="header">{__('Languages', 'translate')}</TableCell>
                                            <TableCell variant="header" align="end">{__('Manage', 'translate')}</TableCell>
                                        </TableHeaderRow>
                                        {data.subsites.map(site => (
                                            <TableRow key={site.id} columnsClassName="grid-cols-[2fr_0.6fr_1.2fr_1.1fr_1.1fr_1.5fr]">
                                                <TableCell className="min-w-0">
                                                    <strong className="block truncate text-translatewp-neutral">{site.name}</strong>
                                                    <a href={site.url} className="block truncate text-translatewp-primary">{site.url}</a>
                                                    {site.siteId && <code className="text-xs">{site.siteId}</code>}
                                                </TableCell>
                                                <TableCell>{site.id}</TableCell>
                                                <TableCell>{site.connected ? __('Connected', 'translate') : __('Not connected', 'translate')}</TableCell>
                                                <TableCell>
                                                    {site.setupEnded ? (
                                                        <span className="inline-flex items-center gap-1.5 text-translatewp-success" data-setup-state="done">
                                                            <CircleCheck size={16} aria-hidden="true" />
                                                            {__('Done', 'translate')}
                                                        </span>
                                                    ) : (
                                                        <a href={site.dashboardUrl} className="inline-flex items-center gap-1.5 text-translatewp-primary" data-setup-state="complete">
                                                            {__('Complete', 'translate')}
                                                            <ArrowRight className="rtl:rotate-180" size={16} aria-hidden="true" />
                                                        </a>
                                                    )}
                                                </TableCell>
                                                <TableCell>{site.languages.length ? site.languages.join(', ') : __('None yet', 'translate')}</TableCell>
                                                <TableCell align="end" className="flex flex-wrap justify-end gap-x-3 gap-y-1">
                                                    <a href={site.dashboardUrl}>{__('Dashboard', 'translate')}</a>
                                                    <a href={site.seoUrl}>{__('SEO Tools', 'translate')}</a>
                                                    <a href={site.lexiconUrl}>{__('Lexicon', 'translate')}</a>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </Table>
                                </div>
                            </Section>
                        ),
                    },
                    {
                        name: 'settings',
                        title: __('Settings', 'translate'),
                        content: (
                            <SettingsScopeSection
                                scope={data.scope}
                                form={{ url: data.actions.url, action: data.actions.scope, nonce: data.actions.nonces.scope }}
                                account={<NetworkConnection data={data} />}
                                proActive={data.connection.pro.active}
                            />
                        ),
                    },
                ] as PageTab[]}
                activeTabName={activeTabName}
                onSelect={onSelectTab}
                bodyClassName="gap-10 pt-6 md:pt-8"
            />
            </div>
            </div>
        </div>
    );
};
