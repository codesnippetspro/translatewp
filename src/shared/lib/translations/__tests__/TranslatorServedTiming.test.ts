import { Translator } from '@/shared/lib/translations/Translator';
import type { ServedTimingEvent } from '@/shared/lib/translations/ServedTranslationTiming';

it('sends the terminal event through the fixed proxy without browser credentials or referrer', async () =>
{
	const originalConfig = window.TranslateWP;
	const originalFetch = globalThis.fetch;
	const requests: Array<{ url: string; init: RequestInit }> = [];
	Object.defineProperty(window, 'TranslateWP', { configurable: true, value: {
		context: { translation_base_url: 'https://example.com/wp-json/translatewp/v1/translation' },
	} });
	globalThis.fetch = (async (url: string, init: RequestInit) =>
	{
		requests.push({ url, init });
		throw new Error('collector unavailable');
	}) as typeof fetch;
	const event: ServedTimingEvent = {
		version: 1, kind: 'switch', path: 'cold', outcome: 'timeout', firstAppliedMs: 100,
		firstVisibleAppliedMs: 112, completedMs: null, elapsedMs: 120000,
		sourceCount: 20, appliedCount: 2, failedGroups: 0, pendingGroups: 2, sampleRate: 0.03125,
	};
	try
	{
		expect(() => new Translator().reportServedTiming(event)).not.toThrow();
		await Promise.resolve();
		expect(requests).toHaveLength(1);
		expect(requests[0].url).toBe('https://example.com/wp-json/translatewp/v1/translation/telemetry/served');
		expect(requests[0].init).toMatchObject({ method: 'POST', credentials: 'omit',
			referrerPolicy: 'no-referrer', keepalive: true });
		expect(JSON.parse(String(requests[0].init.body))).toEqual(event);
	}
	finally
	{
		globalThis.fetch = originalFetch;
		Object.defineProperty(window, 'TranslateWP', { configurable: true, value: originalConfig });
	}
});
