import { __, sprintf } from '@wordpress/i18n';

import type { DashboardMetrics } from '@/shared/api/admin';
import { Card } from '@/shared/components/adminSurface';

const FULL_PERCENT = 100;
const CHART_WIDTH = 600;
const CHART_HEIGHT = 160;
const CHART_PADDING = 12;
const HISTORY_LIMIT = 14;
const PERCENT_SCALE_STEP = 0.1;

export const formatMetric = (value: number) => new Intl.NumberFormat().format(value);

export const InsightGauge = ({ value, label }: { value: number; label: string }) => {
	const percent = Math.max(0, Math.min(FULL_PERCENT, value));
	return (
		<div className="flex items-center gap-5">
			<div
				className="flex h-28 w-28 shrink-0 items-center justify-center rounded-full p-3"
				style={{
					background: `conic-gradient(var(--color-translatewp-primary, #0e3ecd) ${percent}%, #edf0f5 0)`,
				}}
			>
				<span className={'flex h-full w-full items-center justify-center rounded-full '
						+ 'bg-white text-2xl font-semibold'}>
					{sprintf(
						// translators: %d: percentage.
						__('%d%%', 'translate'), Math.round(percent)
					)}
				</span>
			</div>
			<span className="text-sm text-translatewp-muted">{label}</span>
		</div>
	);
};

export const InsightProgress = ({ value, total, label }: {
	value: number; total: number; label: string;
}) => (
	<progress
		className="h-2 w-full overflow-hidden rounded-full accent-translatewp-primary"
		max={Math.max(1, total)}
		value={Math.max(0, Math.min(total, value))}
		aria-label={label}
	/>
);

export const UsageHistory = ({ data }: { data: DashboardMetrics }) => {
	const points = (data.tokenTrend ?? []).slice(-HISTORY_LIMIT).map(point => ({
		...point,
		reusePercent: point.reusePercent ?? 0,
	}));
	const charts = [
		{ key: 'reusePercent' as const, title: __('Translation reuse over time', 'translate'),
			unit: __('Reused translations (%)', 'translate'), line: true },
		{ key: 'cachedHits' as const, title: __('Reused text over time', 'translate'),
			unit: __('Cache hits', 'translate'), line: false },
	];

	return (
		<div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
			{charts.map(chart => {
				const maximum = chart.line
					? FULL_PERCENT : Math.max(1, ...points.map(point => point.cachedHits));
				const values = points.map(point => point.reusePercent);
				const minimum = chart.line && values.length
					? Math.max(0, Math.floor(Math.min(...values) / PERCENT_SCALE_STEP - 1)
						* PERCENT_SCALE_STEP) : 0;
				const width = CHART_WIDTH - CHART_PADDING * 2;
				const height = CHART_HEIGHT - CHART_PADDING * 2;
				const step = width / Math.max(1, points.length);
				const coordinates = points.map((point, index) => ({
					x: CHART_PADDING + step * (index + 0.5),
					y: CHART_HEIGHT - CHART_PADDING - (point[chart.key] - minimum) / (maximum - minimum) * height,
				}));
				return (
					<Card key={chart.key} title={chart.title} className="min-w-0">
						{data.usageAvailable === false && (
							<p className="m-0 text-sm">{__('Usage data is unavailable.', 'translate')}</p>
						)}
						{data.usageAvailable !== false && points.length === 0 && (
							<p className="m-0 text-sm">{__('No daily history available yet.', 'translate')}</p>
						)}
						{data.usageAvailable !== false && points.length > 0 && (
							<>
								<div className="flex justify-between text-xs text-translatewp-muted">
									<span>{chart.unit}</span>
									<span>{sprintf(
										// translators: 1: minimum chart scale value, 2: maximum chart scale value.
										__('Scale: %1$s–%2$s', 'translate'),
										formatMetric(Number(minimum.toFixed(1))), formatMetric(maximum)
									)}</span>
								</div>
								<svg viewBox={`0 0 ${CHART_WIDTH} ${CHART_HEIGHT}`} aria-hidden="true"
									className="w-full text-translatewp-primary" focusable="false">
									<line x1={CHART_PADDING} x2={CHART_WIDTH - CHART_PADDING}
										y1={CHART_HEIGHT - CHART_PADDING} y2={CHART_HEIGHT - CHART_PADDING}
										stroke="currentColor" opacity="0.2" />
									{chart.line && coordinates.map((point, index) => (
										index > 0 && (
											<line key={index} x1={coordinates[index - 1].x} y1={coordinates[index - 1].y}
												x2={point.x} y2={point.y} stroke="currentColor" strokeWidth="2" />
										)
									))}
									{coordinates.map((point, index) => (chart.line ? (
										<circle key={index} cx={point.x} cy={point.y} r="3" fill="currentColor" />
									) : (
										<rect key={index} x={point.x - step / 4} y={point.y} width={step / 2}
											height={CHART_HEIGHT - CHART_PADDING - point.y} rx="3" fill="currentColor" />
									)))}
								</svg>
								<div className="flex justify-between text-xs text-translatewp-muted">
									<span>{points[0].period}</span>
									<span>{points[points.length - 1].period}</span>
								</div>
								<p className="m-0 text-xs text-translatewp-muted">
									{__('Up to 14 daily values in UTC. Today is incomplete.', 'translate')}
								</p>
								<details className="text-sm">
									<summary className="cursor-pointer text-translatewp-primary">
										{__('View daily values', 'translate')}
									</summary>
									<table className="w-full text-start">
										<caption className="sr-only">{chart.title}</caption>
										<thead>
											<tr>
												<th scope="col" className="text-start">
													{__('Date', 'translate')}
												</th>
												<th scope="col" className="text-end">
													{chart.unit}
												</th>
											</tr>
										</thead>
										<tbody>{points.map((point, index) => (
											<tr key={index}>
												<td scope="row">{point.period}</td>
												<td className="text-end">{formatMetric(point[chart.key])}</td>
											</tr>
										))}</tbody>
									</table>
								</details>
							</>
						)}
					</Card>
				);
			})}
		</div>
	);
};
