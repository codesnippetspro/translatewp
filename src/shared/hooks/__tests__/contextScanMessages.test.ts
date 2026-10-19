import { scanDoneMessage, scanFailureMessage, scanProgressMessage } from '@/shared/hooks/useContextScan';

describe('context scan messages', () =>
{
    it('names the seconds elapsed while the scan runs', () =>
    {
        expect(scanProgressMessage(12)).toBe('Reading your site, 12 s');
    });

    it('leaves a done line behind that names the pages read, and the proposal line when nothing was applied', () =>
    {
        expect(scanDoneMessage(8, true)).toBe('Done. The scan read 8 pages and filled in the context, style and tone below.');
        expect(scanDoneMessage(0, true)).toBe('Done. The scan filled in the context, style and tone below.');
        expect(scanDoneMessage(8, false)).toBe('The scan filled in the context and style below. They are used once you confirm, which translates the site again.');
    });

    it('turns every failure code into a plain sentence and never shows a raw code', () =>
    {
        expect(scanFailureMessage('no_scannable_content')).toContain('No published pages or posts');
        expect(scanFailureMessage('context_scan_unavailable')).toContain('could not reach the scan service');
        expect(scanFailureMessage('invalid_scan_response')).toContain('could not use');
        expect(scanFailureMessage('timed_out')).toBe('The scan is taking longer than usual. Scan again.');
        expect(scanFailureMessage('timed_out')).not.toMatch(/\d/);
        expect(scanFailureMessage('something_else')).toBe('The website scan failed. Try again in a minute.');
        expect(scanFailureMessage(undefined)).not.toMatch(/_/);
    });
});
