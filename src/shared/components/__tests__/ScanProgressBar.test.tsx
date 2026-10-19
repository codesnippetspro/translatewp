import { TextEncoder } from 'node:util';
import { renderToStaticMarkup } from 'react-dom/server.node';
import { jest } from '@jest/globals';

// polyfill: jsdom environment lacks TextEncoder which react-dom/server needs
if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

import { ScanProgressBar } from '@/shared/components/ScanProgressBar';

describe('the scan progress bar', () =>
{
    it('renders indeterminate with Finding pages before the first count', () =>
    {
        const html = renderToStaticMarkup(<ScanProgressBar scan={{ pagesTotal: 0, pagesDone: 0, currentPage: null }} finished={false} failed={0} />);
        expect(html).toContain('Finding pages');
        expect(html).toContain('animate-pulse');
        expect(html).not.toContain('Scanning pages');
    });

    it('renders the counted bar with the current page name', () =>
    {
        const html = renderToStaticMarkup(<ScanProgressBar scan={{ pagesTotal: 14, pagesDone: 3, currentPage: { title: 'About us', path: '/about' } }} finished={false} failed={0} />);
        expect(html).toContain('Scanning pages 4 of 14 · About us');
        expect(html).toContain('role="progressbar"');
    });

    it('renders the done state and the failed count', () =>
    {
        const html = renderToStaticMarkup(<ScanProgressBar scan={{ pagesTotal: 14, pagesDone: 14, currentPage: null }} finished={true} failed={2} />);
        expect(html).toContain('Done. Read 14 pages.');
        expect(html).toContain('2 pages could not be read.');
    });
});