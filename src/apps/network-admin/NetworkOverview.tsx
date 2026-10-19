import { __, _n, sprintf } from '@wordpress/i18n';

import type { NetworkAdminData } from '@/apps/network-admin/types';
import { Card, Section } from '@/shared/components/adminSurface';
import { ProStatus } from '@/shared/components/connection/ConnectionPanel';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { QuotaCard } from '@/apps/admin/components/Dashboard/QuotaCard';
import { FeedbackForm } from '@/apps/admin/components/Transparency/FeedbackForm';

const Figure = ({ value, label }: { value: string; label: string }) => (
    <div className="flex flex-col">
        <span className="text-3xl font-semibold text-translatewp-neutral">{value}</span>
        <span className="text-sm text-translatewp-muted">{label}</span>
    </div>
);

export const languageCounts = (subsites: NetworkAdminData['subsites']): Array<{ locale: string; sites: number }> =>
{
    const counts = new Map<string, number>();
    for (const site of subsites)
    {
        for (const locale of site.languages) counts.set(locale, (counts.get(locale) ?? 0) + 1);
    }

    return Array.from(counts, ([locale, sites]) => ({ locale, sites })).sort((a, b) => b.sites - a.sites || a.locale.localeCompare(b.locale));
};

export const NetworkOverview = ({ data, subsitesHref }: { data: NetworkAdminData; subsitesHref: string }) =>
{
    const total = data.subsites.length;
    const connected = data.subsites.filter(site => site.connected).length;
    const setUp = data.subsites.filter(site => site.setupEnded).length;
    const pending = data.subsites.filter(site => site.connected && !site.setupEnded);
    const languages = languageCounts(data.subsites);

    return (
        <>
            <ProStatus pro={data.connection.pro} onlyWhenActionable framed />
            <Section title={__('Network at a glance', 'translate')}>
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <Card title={__('Subsites', 'translate')}>
                        <div className="flex flex-wrap gap-8">
                            <Figure value={`${connected} / ${total}`} label={__('connected', 'translate')} />
                            <Figure value={`${setUp} / ${total}`} label={__('setup complete', 'translate')} />
                        </div>
                        {pending.length > 0 && (
                            <p className="m-0 text-sm text-translatewp-muted!" data-setup-pending>
                                {sprintf(
                                    /* translators: %s: subsite names. */
                                    _n('Setup is still open on %s.', 'Setup is still open on: %s.', pending.length, 'translate'),
                                    pending.map(site => site.name).join(', ')
                                )}
                            </p>
                        )}
                        <a href={subsitesHref} className="text-sm text-translatewp-primary">{__('Manage sites', 'translate')}</a>
                    </Card>
                    <Card title={__('Languages in use', 'translate')}>
                        {languages.length === 0
                            ? <p className="m-0 text-sm text-translatewp-muted!">{__('No subsite has chosen its languages yet.', 'translate')}</p>
                            : (
                                <ul className="m-0 flex list-none flex-col gap-2" data-language-counts>
                                    {languages.map(({ locale, sites }) => (
                                        <li key={locale} className="m-0 flex items-center justify-between gap-3 text-sm text-translatewp-neutral">
                                            <span className="flex items-center gap-2">
                                                {getCountryFlagFromLocale(locale)}
                                                {getLanguageNameFromLocale(locale) || locale}
                                            </span>
                                            <span className="text-translatewp-muted">
                                                {sprintf(
                                                    /* translators: %d: number of subsites. */
                                                    _n('%d subsite', '%d subsites', sites, 'translate'),
                                                    sites
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                    </Card>
                    {data.connection.connected && <QuotaCard />}
                </div>
            </Section>
            <FeedbackForm />
        </>
    );
};
