import { useTranslationReadinessState } from '@/shared/hooks/useTranslationReadiness';
import { useQuery } from "@tanstack/react-query";
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
import { getDashboardMetricsQueryOptions, getIndexPagesQueryOptions } from "@/shared/query-options/admin";
import { TranslationPausedNotice } from "@/apps/admin/components/Dashboard/TranslationPausedNotice";
import { FinishSetupBanner } from "@/apps/admin/components/Dashboard/FinishSetupBanner";
import { OverviewNextAction } from "@/apps/admin/components/Dashboard/OverviewNextAction";
import { getConnectStatusQueryOptions } from "@/shared/query-options/connect";
import { PageBody, PageHeader } from '@/apps/admin/components/PageLayout';
import { ProInstallCard } from '@/apps/admin/components/Dashboard/ProInstallCard';
import { ProWelcome } from '@/apps/admin/components/Dashboard/ProWelcome';
import { pendingProAction } from '@/shared/lib/pro/proState';
import { QuotaCard } from '@/apps/admin/components/Dashboard/QuotaCard';
import { ReadinessInsights } from './ReadinessInsights';
import { InsightGauge, InsightProgress, UsageHistory } from './InsightCharts';

const formatNumber = (value: number) => new Intl.NumberFormat().format(value);

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
    const { status: liveStatus, unconfirmed } = useTranslationReadinessState(!isLoading);
    const { data: pages } = useQuery({ ...getIndexPagesQueryOptions(), enabled: false, refetchInterval: false });
    // The install card owns the install and activate states; the
    // plain Pro status stays for the update and payment timeline states.
    const proCardShown = pendingProAction(connectStatus) !== null;

    return (
        <div className="flex flex-col">
            <PageHeader
                title={__('Dashboard', 'translate')}
                description={connected && <ConnectionStatusText />}
                actions={connected && <OverviewNextAction liveStatus={liveStatus} />}
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
                    <Card title={__('Connection', 'translate')}>
                        <ConnectionPanel />
                    </Card>
                )}

                {isLoading && (
                    <p className="m-0 text-sm text-translatewp-muted" role="status">
                        {__('Loading translation figures…', 'translate')}
                    </p>
                )}

                {(isError || (!isLoading && !data)) && (
                    <p className="m-0 text-sm text-translatewp-muted">{__('Failed to load dashboard metrics.', 'translate')}</p>
                )}

                {data && !isLoading && (
                    <>
                        <ReadinessInsights data={data} status={liveStatus} pages={pages} unconfirmed={unconfirmed} />
                        <DashboardMetricsContent data={data} connected={connected}
                            translationsUnconfirmed={unconfirmed} />
                    </>
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
                <Section
                    heading={__("Quick settings", "translate")}
                    description={__("The main settings, right here. Everything else lives under Settings.", "translate")}
                >
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
                        <LanguagesCard />
                        <TranslationCard />
                        <IndexingSummary liveStatus={liveStatus} />
                    </div>
                </Section>
            </PageBody>
        </div>
    );
}

export const SummaryStats = ({ data, unconfirmed = false }: {
    data: DashboardMetrics; unconfirmed?: boolean;
}) => (
    <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
        <Card title={__('Page translations in the last 24 hours', 'translate')}>
            <span className="text-3xl font-semibold text-translatewp-neutral">
                {unconfirmed ? __('Unavailable', 'translate') : formatNumber(data.translationsToday)}
            </span>
            <p className="m-0 text-sm text-translatewp-muted">
                {__('Currently ready pages completed in the last 24 hours, counted once per language.', 'translate')}
            </p>
        </Card>
        <Card title={__('Reused translations', 'translate')}>
            {data.usageAvailable === false && <p className="m-0 text-sm">
                {__('Usage data is unavailable.', 'translate')}
            </p>}
            {data.usageAvailable !== false && data.cacheLookups === 0 && <p className="m-0 text-sm">
                {__('No translation lookups recorded today or yesterday.', 'translate')}
            </p>}
            {data.usageAvailable !== false && data.cacheLookups !== 0 && <InsightGauge
                value={data.cacheHitRatio}
                label={__('Share of lookups served from existing translations today and yesterday.', 'translate')}
            />}
        </Card>
    </div>
);

export const DashboardMetricsContent = ({ data, connected = false, translationsUnconfirmed = false }: {
    data: DashboardMetrics; connected?: boolean; translationsUnconfirmed?: boolean;
}) =>
{
    return (
        <>
            <Section heading={__("Recent activity", "translate")}>
                <SummaryStats data={data} unconfirmed={translationsUnconfirmed} />
                <UsageHistory data={data} />
            </Section>

            <Section
                heading={__("Usage", "translate")}
                description={connected
                    ? __("What is available to your account and where the translations went.", "translate")
                    : __("Where the translations went.", "translate")}
            >
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    {connected && <QuotaCard />}

                    <Card title={__('Ready page translations by language', 'translate')}>
                        {data.languageUsage.length === 0 ? (
                            <p className="m-0 text-sm text-translatewp-muted">{translationsUnconfirmed
                                ? __('Readiness could not be refreshed.', 'translate')
                                : __('No translation data yet.', 'translate')}</p>
                        ) : (
                            <Table>
                                <TableHeaderRow>
                                    <TableCell variant="header">{__('Language', 'translate')}</TableCell>
                                    <TableCell variant="header">{__('Translations', 'translate')}</TableCell>
                                    <TableCell variant="header">{__('Share', 'translate')}</TableCell>
                                </TableHeaderRow>

                                {data.languageUsage.map((lang, index) => (
                                    <TableRow key={`${lang.locale}-${index}`}>
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

            <Section
                heading={__("Content", "translate")}
                description={__("Recently completed translations and terminology rules for your content.", "translate")}
            >
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
                    <Card title={__('Recently translated pages', 'translate')}>
                        {data.recentContent.length === 0 ? (
                            <p className="m-0 text-sm text-translatewp-muted">{translationsUnconfirmed
                                ? __('Readiness could not be refreshed.', 'translate')
                                : __('No translated content yet.', 'translate')}</p>
                        ) : (
                            <Table>
                                <TableHeaderRow>
                                    <TableCell variant="header">{__('Page', 'translate')}</TableCell>
                                    <TableCell variant="header">{__('Language', 'translate')}</TableCell>
                                </TableHeaderRow>

                                {data.recentContent.map((item, index) => (
                                    <TableRow key={`${item.page}-${index}`}>
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
                        {data.lexiconSummary && (
                            <div className="flex flex-col gap-3">
                                <p className="m-0 text-sm text-translatewp-muted">
                                    {sprintf(
                                        // translators: 1: total Lexicon terms, 2: terms applying to every language.
                                        __('Total terms: %1$d. Terms for every language: %2$d.', 'translate'),
                                        data.lexiconSummary.total, data.lexiconSummary.global
                                    )}
                                </p>
                                {Object.entries(data.lexiconSummary.languages).map(([locale, count]) => (
                                    <div key={locale} className="flex flex-col gap-1">
                                        <div className="flex justify-between text-sm">
                                            <span>{getLanguageNameFromLocale(locale) || locale}</span>
                                            <span>{formatNumber(count)}</span>
                                        </div>
                                        <InsightProgress value={count} total={data.lexiconSummary!.total}
                                            label={getLanguageNameFromLocale(locale) || locale} />
                                    </div>
                                ))}
                                <p className="m-0 text-xs text-translatewp-muted">
                                    {__('Applicable terms per language. A term can apply to more than one language.', 'translate')}
                                </p>
                            </div>
                        )}
                        {data.recentLexicon.length === 0 ? (
                            <div className="flex flex-col items-start gap-3">
                                <p className="m-0 text-sm text-translatewp-muted">{__('No lexicon entries yet. Fix how a name, a product or a phrase is translated, in every language, once.', 'translate')}</p>
                                <a href="admin.php?page=translatewp-lexicon#add" className="components-button is-secondary rounded-sm!" data-lexicon-add>
                                    {__('Add your first term', 'translate')}
                                </a>
                            </div>
                        ) : (
                            <Table>
                                <TableHeaderRow>
                                    <TableCell variant="header">{__('Term', 'translate')}</TableCell>
                                    <TableCell variant="header">{__('Added', 'translate')}</TableCell>
                                </TableHeaderRow>

                                {data.recentLexicon.map((entry, index) => (
                                    <TableRow key={`${entry.term}-${index}`}>
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
