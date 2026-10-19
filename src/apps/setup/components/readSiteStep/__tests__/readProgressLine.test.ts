import { readProgressLine, setupExtractionConfig } from '@/apps/setup/components/readSiteStep';
import { scanProgressMessage } from '@/shared/hooks/useContextScan';

describe('read your site progress line', () =>
{
    it('says which page is being read, the segments found and the seconds with one decimal', () =>
    {
        expect(readProgressLine('fetching', { processed: 2, failed: 1, segments: 40 }, 9, 65.04)).toBe('Reading page 3 of 9, found 40 text segments in 65.0 sec');
        expect(readProgressLine('fetching', { processed: 0, failed: 0, segments: 0 }, 9, 0.36)).toBe('Reading page 0 of 9, found 0 text segments in 0.4 sec');
    });

    it('closes with the pages read and the segments found and never says nothing is translated', () =>
    {
        const line = readProgressLine('done', { processed: 9, failed: 0, segments: 312 }, 9, 80);
        expect(line).toBe('Done. 9 pages read, 312 text segments found.');
        expect(line).not.toContain('Nothing is translated yet');
    });

    it('shows nothing before the read starts', () =>
    {
        expect(readProgressLine('idle', null, 0, 0)).toBe('');
    });
});

describe('setup extraction config', () =>
{
    it('builds a well formed config from the wizard data without any saved settings', () =>
    {
        (window as unknown as { setupData: { defaultLanguage: string } }).setupData = { defaultLanguage: 'de_DE' };
        const config = setupExtractionConfig();
        expect(config.languages[0]?.locale).toBe('de');
        expect(config.languages[0]?.default).toBe(true);
        // Alt text is read only with Pro active.
        expect(config.extractionOptions).toEqual({ includeAlt: false, includeAttributes: true });
        (window as unknown as { translatewpPro?: unknown }).translatewpPro = { connected: true, entitled: true, installed: true, active: true };
        expect(setupExtractionConfig().extractionOptions).toEqual({ includeAlt: true, includeAttributes: true });
        delete (window as unknown as { translatewpPro?: unknown }).translatewpPro;
        expect(config.aiSettings.lexicon_terms).toEqual([]);
    });
});

describe('scan progress message with chunks', () =>
{
    it('names the page being read while collecting and the analysis with its time after', () =>
    {
        expect(scanProgressMessage(3, { stage: 'collecting', sampled: 2, total: 7 })).toBe('Reading page 2 of 7');
        expect(scanProgressMessage(41, { stage: 'analysing', sampled: 7, total: 7 })).toBe('Analysing 7 pages, 41 s');
        expect(scanProgressMessage(5)).toBe('Reading your site, 5 s');
    });
});
