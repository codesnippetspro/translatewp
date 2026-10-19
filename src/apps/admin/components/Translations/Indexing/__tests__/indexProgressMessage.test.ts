import { indexFailureMessage, indexProgressMessage, scanProgressMessage } from '../indexProgressMessage';

describe('indexProgressMessage', () =>
{
    it('counts pages fetched of the total while fetching', () =>
    {
        expect(indexProgressMessage('fetching', { processed: 10, failed: 2 }, 27, ['de', 'en']))
            .toBe('Fetching 12 of 27 pages');
    });

    it('never shows a fetched count past the total', () =>
    {
        expect(indexProgressMessage('fetching', { processed: 90, failed: 11 }, 27, ['de', 'en']))
            .toBe('Fetching 27 of 27 pages');
    });

    it('counts rows instead of pages when the total could not be collapsed to distinct pages', () =>
    {
        expect(indexProgressMessage('fetching', { processed: 5, failed: 0 }, 20, ['de', 'en'], 'rows'))
            .toBe('Fetching 5 of 20 rows');
    });

    it('uses singular row copy for a single row total', () =>
    {
        expect(indexProgressMessage('fetching', { processed: 0, failed: 0 }, 1, ['de'], 'rows'))
            .toBe('Fetching 0 of 1 row');
    });

    it('names the page count while translating', () =>
    {
        expect(indexProgressMessage('translating', { processed: 27, failed: 0 }, 27, ['de', 'en']))
            .toBe('Translating 27 pages');
    });

    it('names the pages sent and the target languages when done, never a readiness it cannot know', () =>
    {
        expect(indexProgressMessage('done', { processed: 27, failed: 0 }, 27, ['de', 'en']))
            .toBe('Sent 27 pages for translation in German and English.');
        expect(indexProgressMessage('done', { processed: 0, failed: 0 }, 27, ['de', 'en']))
            .toBe('Nothing new to translate.');
    });

    it('uses singular copy for a single page and language', () =>
    {
        expect(indexProgressMessage('done', { processed: 1, failed: 0 }, 1, ['de']))
            .toBe('Sent 1 page for translation in German.');
    });

    it('appends the failed page count to the done line when failures exist', () =>
    {
        expect(indexProgressMessage('done', { processed: 27, failed: 11 }, 38, ['de', 'it']))
            .toBe('Sent 27 pages for translation in German and Italian. 11 pages could not be fetched.');
    });

    it('uses singular copy for a single failed page in the done line', () =>
    {
        expect(indexProgressMessage('done', { processed: 1, failed: 1 }, 2, ['de']))
            .toBe('Sent 1 page for translation in German. 1 page could not be fetched.');
    });
});

describe('indexFailureMessage', () =>
{
    it('names the failed count and the next step', () =>
    {
        const message = indexFailureMessage(3);

        expect(message).toContain('3 pages could not be read');
        expect(message).toContain('Read my site');
    });

    it('uses singular copy for a single failed page', () =>
    {
        expect(indexFailureMessage(1)).toContain('1 page could not be read');
    });
});

describe('scanProgressMessage', () =>
{
    it('says Finding pages before the first count arrives', () =>
    {
        expect(scanProgressMessage({ pagesTotal: 0, pagesDone: 0, currentPage: null }, false))
            .toBe('Finding pages…');
    });

    it('says Scanning pages X of Y with the current page named', () =>
    {
        expect(scanProgressMessage({ pagesTotal: 14, pagesDone: 3, currentPage: { title: 'About us', path: '/about' } }, false))
            .toBe('Scanning pages 4 of 14 · About us');
    });

    it('falls back to the path when the title is empty', () =>
    {
        expect(scanProgressMessage({ pagesTotal: 9, pagesDone: 2, currentPage: { title: '', path: '/contact' } }, false))
            .toBe('Scanning pages 3 of 9 · /contact');
    });

    it('counts without a current page when nothing is claimed', () =>
    {
        expect(scanProgressMessage({ pagesTotal: 9, pagesDone: 2, currentPage: null }, false))
            .toBe('Scanning pages 2 of 9');
    });

    it('never counts past the total', () =>
    {
        expect(scanProgressMessage({ pagesTotal: 5, pagesDone: 5, currentPage: { title: 'Last', path: '/last' } }, false))
            .toBe('Scanning pages 5 of 5 · Last');
    });

    it('names the read page count when finished', () =>
    {
        expect(scanProgressMessage({ pagesTotal: 14, pagesDone: 14, currentPage: null }, true))
            .toBe('Done. Read 14 pages.');
    });

    it('uses singular copy for one read page', () =>
    {
        expect(scanProgressMessage({ pagesTotal: 1, pagesDone: 1, currentPage: null }, true))
            .toBe('Done. Read 1 page.');
    });
});
