import { useQuery } from "@tanstack/react-query";
import { Spinner } from "@wordpress/components";
import { __, sprintf } from "@wordpress/i18n";

import { type DashboardMetrics } from "@/shared/api/admin";
import { Card, CardLink, IndexingSummary, LanguagesCard, Section, TranslationCard } from "@/apps/admin/components/Dashboard/cards";
import { Table, TableCell, TableHeaderRow, TableRow } from "@/shared/components/form/table";
import { AddressChangedNotice } from "@/shared/components/connection/AddressChangedNotice";
import { ConnectionPanel, ProStatus } from "@/shared/components/connection/ConnectionPanel";
import { ConnectionStatusText } from "@/shared/components/connection/ConnectionStatusText";
import { getCountryFlagFromLocale } from "@/shared/lib/languages";
import { getLanguageNameFromLocale } from "@/shared/lib/languages/languageName";
import { InfoTip } from "@/shared/components/infoTip";
import { getDashboardMetricsQueryOptions } from "@/shared/query-options/admin";
import { TranslationPausedNotice } from "@/apps/admin/components/Dashboard/TranslationPausedNotice";
import { FinishSetupBanner } from "@/apps/admin/components/Dashboard/FinishSetupBanner";
import { OverviewNextAction } from "@/apps/admin/components/Dashboard/OverviewNextAction";
import { getConnectStatusQueryOptions } from "@/shared/query-options/connect";
import { PageBody, PageHeader } from '@/apps/admin/components/PageLayout';
import { ProInstallCard } from '@/apps/admin/components/Dashboard/ProInstallCard';
import { ProWelcome } from '@/apps/admin/components/Dashboard/ProWelcome';
import { pendingProAction } from '@/shared/lib/pro/proState';
import { QuotaCard } from '@/apps/admin/components/Dashboard/QuotaCard';

const formatNumber = (value: number) => new Intl.NumberFormat("en-US").format(value);

const ShareBar = ({ value, color }: { value: number; color: string }) => (
    <div className="flex items-center gap-2 w-full">
        <span className="text-sm text-translatewp-muted shrink-0 w-10">{value}%</span>
        <div className="h-1.5 w-full bg-translatewp-selection rounded-full overflow-hidden">
            <div
                className="h-full rounded-full"
                style={{ width: `${Math.min(100, value)}%`, backgroundColor: color }}
            />
        </div>
    </div>
);

export const Dashboard = () =>
{
    const { data, isLoading, isError } = useQuery(getDashboardMetricsQueryOptions());
    const { data: connectStatus, isPending: connectStatusPending } = useQuery(getConnectStatusQueryOptions());
    const connected = connectStatus?.connected === true;
    // The install card owns the install and activate states; the
    // plain Pro status stays for the update and payment timeline states.
    const proCardShown = pendingProAction(connectStatus) !== null;

    return (
        <div className="flex flex-col">
            <PageHeader
                title="Dashboard"
                description={connected && <ConnectionStatusText />}
                actions={connected && <OverviewNextAction />}
            />

            <PageBody>
                {connected && (
                    <>
                        {/* Once Pro is active the welcome takes the install card's slot. */}
                        <ProInstallCard />
                        <ProWelcome />
                        <AddressChangedNotice />
                        <FinishSetupBanner />
                        <TranslationPausedNotice />
                        {connectStatus?.pro && !proCardShown && (
                            <ProStatus pro={connectStatus.pro} onlyWhenActionable framed accountUrl={connectStatus.portalSiteUrl || connectStatus.portalSitesUrl} />
                        )}
                    </>
                )}
                {/* The connection card waits for the status: a site is never shown
                    as disconnected before the answer is in. */}
                {!connected && !connectStatusPending && (
                    <Card title="Connection">
                        <ConnectionPanel />
                    </Card>
                )}

                {isLoading && (
                    <div className="flex items-center justify-center p-16">
                        <Spinner />
                    </div>
                )}

                {(isError || (!isLoading && !data)) && (
                    <p className="m-0 text-sm text-translatewp-muted">Failed to load dashboard metrics.</p>
                )}

                {data && !isLoading && (
                    <DashboardMetricsContent data={data} connected={connected} />
                )}
                {data && !isLoading && (data.anchorMissing?.length ?? 0) > 0 && (
                    <p className="m-0 text-sm text-translatewp-muted" data-anchor-missing>
                        {sprintf(
                            /* translators: %s: page addresses, comma separated. */
                            __('The anchored switcher\'s element was not found on: %s. Those pages show the fallback chosen under Settings > Switcher.', 'translate'),
                            (data.anchorMissing ?? []).map(url => url.replace(/^https?:\/\/[^/]+/, '') || '/').join(', ')
                        )}
                    </p>
                )}

                {/* The controls follow the figures, on the same store as Settings. */}
                <Section title="Quick settings" description="The main settings, right here. Everything else lives under Settings.">
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <LanguagesCard />
                        <TranslationCard />
                        <IndexingSummary />
                    </div>
                </Section>
            </PageBody>
        </div>
    );
}

export const SummaryStats = ({ data }: { data: DashboardMetrics }) =>
{
    const summaryStats = [
        {
            label: __('Page translations today', 'translate'),
            value: formatNumber(data.translationsToday),
            helper: __('finished since midnight, one per page and language', 'translate'),
            explanation: sprintf(
                /* translators: %s: number of text segments served from the translation cache today. */
                __('A page counts once per language when its translation finishes. Separately, %s pieces of already translated text (headings, paragraphs, button labels) were shown to visitors today without a new translation.', 'translate'),
                formatNumber(data.cacheHitsToday)
            ),
        },
        {
            label: __('Reused translations', 'translate'),
            value: `${data.cacheHitRatio}%`,
            helper: __('of what visitors read in the last 48 hours was already translated', 'translate'),
            explanation: __('Of all the text visitors opened in the last 48 hours, this share came from translations the site already had, so it cost nothing and showed at once. The rest was new or changed text, translated fresh.', 'translate'),
        },
    ];

    return (
        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
            {summaryStats.map((stat) => (
                <div
                    key={stat.label}
                    className="flex flex-col gap-2 rounded-lg border border-translatewp-border bg-white p-6"
                >
                    <span className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-translatewp-muted">
                        {stat.label}
                        <InfoTip text={stat.explanation} />
                    </span>
                    <span className="text-3xl font-semibold text-translatewp-neutral">
                        {stat.value}
                    </span>
                    <span className="text-sm text-translatewp-muted">{stat.helper}</span>
                </div>
            ))}
        </div>
    );
};

export const DashboardMetricsContent = ({ data, connected = false }: { data: DashboardMetrics; connected?: boolean }) =>
{
    return (
        <>
            <Section title="Today" description={__("Since midnight.", "translate")}>
                <SummaryStats data={data} />
            </Section>

            <Section
                title="Usage"
                description={connected
                    ? __("What is available to your account and where the translations went.", "translate")
                    : __("Where the translations went.", "translate")}
            >
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    {connected && <QuotaCard />}

                    <Card title="Language usage">
                        {data.languageUsage.length === 0 ? (
                            <p className="m-0 text-sm text-translatewp-muted">No translation data yet.</p>
                        ) : (
                            <Table>
                                <TableHeaderRow columnsClassName="grid-cols-3">
                                    <TableCell variant="header">Language</TableCell>
                                    <TableCell variant="header">Translations</TableCell>
                                    <TableCell variant="header">Share</TableCell>
                                </TableHeaderRow>

                                {data.languageUsage.map((lang, index) => (
                                    <TableRow key={`${lang.locale}-${index}`} columnsClassName="grid-cols-3">
                                        <TableCell as="div" className="flex items-center gap-2 text-translatewp-neutral">
                                            {getCountryFlagFromLocale(lang.locale)}
                                            <span>{getLanguageNameFromLocale(lang.locale) ?? lang.name}</span>
                                        </TableCell>
                                        <TableCell>{formatNumber(lang.translations)}</TableCell>
                                        <TableCell as="div">
                                            <ShareBar value={lang.share} color={lang.barColor} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </Table>
                        )}
                    </Card>
                </div>
            </Section>

            <Section title="Content" description="The pages visitors read most in another language, and the terms you fixed lately.">
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <Card title="Most translated content">
                        {data.recentContent.length === 0 ? (
                            <p className="m-0 text-sm text-translatewp-muted">No translated content yet.</p>
                        ) : (
                            <Table>
                                <TableHeaderRow columnsClassName="grid-cols-2">
                                    <TableCell variant="header">Page</TableCell>
                                    <TableCell variant="header">Language</TableCell>
                                </TableHeaderRow>

                                {data.recentContent.map((item, index) => (
                                    <TableRow key={`${item.page}-${index}`} columnsClassName="grid-cols-2">
                                        <TableCell as="div">
                                            <a href={item.href} className="text-translatewp-primary underline">
                                                {item.page}
                                            </a>
                                        </TableCell>
                                        <TableCell>{(item.locale && getLanguageNameFromLocale(item.locale)) || item.language}</TableCell>
                                    </TableRow>
                                ))}
                            </Table>
                        )}
                    </Card>

                    <Card title={__('Lexicon entries', 'translate')} badge={<InfoTip text={__('The Lexicon fixes how a word or phrase is translated: a brand name that stays as it is, a product name, or a term with one agreed translation per language. Every page that uses it follows the rule.', 'translate')} />} action={data.recentLexicon.length > 0 ? <CardLink href="admin.php?page=translatewp-lexicon">{__('View all', 'translate')}</CardLink> : undefined}>
                        {data.recentLexicon.length === 0 ? (
                            <div className="flex flex-col items-start gap-3">
                                <p className="m-0 text-sm text-translatewp-muted">{__('No lexicon entries yet. Fix how a name, a product or a phrase is translated, in every language, once.', 'translate')}</p>
                                <a href="admin.php?page=translatewp-lexicon#add" className="components-button is-secondary rounded-sm!" data-lexicon-add>
                                    {__('Add your first term', 'translate')}
                                </a>
                            </div>
                        ) : (
                            <Table>
                                <TableHeaderRow columnsClassName="grid-cols-2">
                                    <TableCell variant="header">Term</TableCell>
                                    <TableCell variant="header">Added</TableCell>
                                </TableHeaderRow>

                                {data.recentLexicon.map((entry, index) => (
                                    <TableRow key={`${entry.term}-${index}`} columnsClassName="grid-cols-2">
                                        <TableCell as="div">
                                            <a href={entry.href} className="text-translatewp-primary underline">
                                                {entry.term}
                                            </a>
                                        </TableCell>
                                        <TableCell>{entry.added}</TableCell>
                                    </TableRow>
                                ))}
                            </Table>
                        )}
                    </Card>
                </div>
            </Section>
        </>
    );
};
