import { __, sprintf } from '@wordpress/i18n';

import type { IndexStatus } from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { buildAdminUrl } from '@/apps/admin/hooks/useNavigate';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import type { DashboardMetrics, IndexPagesResponse } from '@/shared/api/admin';
import { Card } from '@/shared/components/adminSurface';
import { getLanguageNameFromLocale } from '@/shared/lib/languages/languageName';
import { CardLink } from './cards';
import { formatMetric, InsightGauge, InsightProgress } from './InsightCharts';

const FULL_PERCENT = 100;
const PREVIEW_PAGES = 8;
const nameOf = (locale: string) => getLanguageNameFromLocale(locale) || locale;

export const ReadinessInsights = ({ data, status, pages, unconfirmed }: {
	data: DashboardMetrics;
	status?: IndexStatus;
	pages?: IndexPagesResponse;
	unconfirmed: boolean;
}) => {
	const configured = useAdminSettingsStore(state => state.persistedSettings['languages.list']);
	const languages = configured.filter(language => !language.default && language.active);
	const defaultLanguage = configured.find(language => language.default);
	const rows = languages.map(language => ({
		locale: language.locale,
		ready: status?.languages[language.locale]?.ready ?? 0,
		total: status?.languages[language.locale]?.readyTotal ?? 0,
	}));
	const total = rows.reduce((sum, row) => sum + row.total, 0);
	const ready = rows.reduce((sum, row) => sum + row.ready, 0);
	const activeLocales = new Set(languages.map(language => language.locale));
	const failures = new Set((status?.failed ?? [])
		.filter(item => activeLocales.has(item.language))
		.map(item => `${item.url}|${item.language}`)).size;
	const readable = Boolean(status);
	const indexingUrl = buildAdminUrl('', 'indexing');
	const summaries = [
		{ label: __('Active translation languages', 'translate'), value: languages.length },
		{ label: __('Pages read', 'translate'), value: status?.read?.pages },
		{ label: __('Source segments found', 'translate'), value: status?.read?.segments },
		{ label: __('Lexicon terms', 'translate'), value: data.lexiconSummary?.total },
	];
	const attention = [
		{ label: __('Failed page translations', 'translate'), value: failures },
		{ label: __('Pages changed since reading', 'translate'), value: status?.changedPages ?? 0 },
		{ label: __('Pages that could not be read', 'translate'), value: status?.read?.failed ?? 0 },
	];
	const pageStatuses = {
		indexed: __('Ready', 'translate'), pending: __('Pending', 'translate'),
		failed: __('Failed', 'translate'), retranslating: __('Translating', 'translate'),
	};

	return (
		<section className="flex flex-col gap-6" aria-labelledby="twp-readiness-heading">
			<div className="flex flex-wrap items-center justify-between gap-2">
				<h2 id="twp-readiness-heading" className="m-0! text-lg!">
					{__('Your translation overview', 'translate')}
				</h2>
				{defaultLanguage && <span className="text-sm text-translatewp-muted">
					{sprintf(
						// translators: %s: default language name.
						__('Default language: %s', 'translate'), nameOf(defaultLanguage.locale)
					)}
				</span>}
			</div>
			<div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
				{summaries.map(item => (
					<div key={item.label} className="rounded-lg border border-translatewp-border bg-white p-5">
						<div className="text-3xl font-semibold text-translatewp-neutral">
							{item.value === undefined ? __('Unavailable', 'translate') : formatMetric(item.value)}
						</div>
						<p className="mb-0! mt-2! text-sm text-translatewp-muted">{item.label}</p>
					</div>
				))}
			</div>
			<div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
				<Card title={__('Translation readiness', 'translate')}
					action={<CardLink href={indexingUrl}>{__('View pages', 'translate')}</CardLink>}>
					{!status && !unconfirmed && <p role="status">{__('Checking readiness…', 'translate')}</p>}
					{unconfirmed && <p role="status">
						{status
							? __('Readiness could not be refreshed. Counts shown are from the last check.', 'translate')
							: __('Readiness could not be refreshed.', 'translate')}
					</p>}
					{readable && total > 0 && <InsightGauge value={ready / total * FULL_PERCENT}
						label={sprintf(
							// translators: 1: ready page translations, 2: tracked page translations.
							__('%1$s of %2$s indexed page translations are ready.', 'translate'),
							formatMetric(ready), formatMetric(total)
						)} />}
					{readable && total === 0 && <p className="m-0 text-sm">
						{__('No indexed page translations to measure yet.', 'translate')}
					</p>}
					{readable && rows.map(row => (
						<div key={row.locale} className="flex flex-col gap-2">
							<div className="flex justify-between gap-3 text-sm">
								<span>{nameOf(row.locale)}</span>
								<span>{sprintf(
									// translators: 1: ready pages, 2: tracked pages for a language.
									__('%1$s / %2$s ready', 'translate'),
									formatMetric(row.ready), formatMetric(row.total)
								)}</span>
							</div>
							<InsightProgress value={row.ready} total={row.total} label={nameOf(row.locale)} />
						</div>
					))}
					<p className="m-0 text-xs text-translatewp-muted">
						{__('Active languages only. Pages outside the index are not measured.', 'translate')}
					</p>
				</Card>
				<Card title={__('Needs attention', 'translate')}
					action={<CardLink href={indexingUrl}>{__('Review pages', 'translate')}</CardLink>}>
					<dl className="m-0 divide-y divide-translatewp-border">
						{attention.map(item => <div key={item.label}
							className="flex justify-between gap-3 py-3">
							<dt className="text-sm">{item.label}</dt>
							<dd className="m-0 text-xl font-semibold">
								{readable ? formatMetric(item.value) : __('Unavailable', 'translate')}
							</dd>
						</div>)}
					</dl>
					<p className="m-0 text-xs text-translatewp-muted">
						{__('Failures can include pages visitors found outside the index.', 'translate')}
					</p>
					{readable && status?.scheduledTasksLate && <p className="m-0 text-sm text-translatewp-error">
						{__('Scheduled translation work is delayed.', 'translate')}
					</p>}
				</Card>
			</div>
			{readable && pages && pages.pages.length > 0 && languages.length > 0 && (
				<details className="rounded-lg border border-translatewp-border bg-white p-5">
					<summary className="cursor-pointer font-semibold">
						{__('Page-by-language overview', 'translate')}
					</summary>
					<div className="mt-4 overflow-x-auto">
						<table className="w-full text-start text-sm">
							<caption className="sr-only">
								{__('Translation status by page and language', 'translate')}
							</caption>
							<thead><tr><th scope="col" className="p-2 text-start">{__('Page', 'translate')}</th>
								{languages.map(language => <th key={language.locale} scope="col" className="p-2">
									{nameOf(language.locale)}
								</th>)}
							</tr></thead>
							<tbody>{pages.pages.slice(0, PREVIEW_PAGES).map(page => (
								<tr key={page.url} className="border-t border-translatewp-border">
									<th scope="row" className="max-w-xs p-2 text-start font-normal break-words">
										<a href={page.url} className="text-translatewp-primary underline">
											{page.title || page.url}
										</a>
									</th>
									{languages.map(language => {
										const translation = page.languages[language.locale];
										return <td key={language.locale} className="p-2 text-center">
											{translation ? pageStatuses[translation.status] : __('Not started', 'translate')}
										</td>;
									})}
								</tr>
							))}</tbody>
						</table>
					</div>
					<CardLink href={indexingUrl}>
						{__('View all pages and translation details', 'translate')}
					</CardLink>
				</details>
			)}
		</section>
	);
};
