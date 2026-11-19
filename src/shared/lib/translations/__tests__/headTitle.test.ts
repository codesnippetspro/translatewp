import { HeadTitleTranslator, type SeoStringTranslator } from '@/shared/lib/translations/headTitle';
import type { SeoStringRequest } from '@/shared/lib/translations/Translator';

type Call = SeoStringRequest[];

function fakeTranslator(map: Record<string, string | null>): { translator: SeoStringTranslator; calls: Call[] }
{
    const calls: Call[] = [];
    const translator: SeoStringTranslator = {
        async translateSeoStrings(requests, contextKey)
        {
            expect(contextKey).toBe('seo:title');
            calls.push(requests);
            return requests.map(({ source }) => Object.prototype.hasOwnProperty.call(map, source) ? map[source] : null);
        },
    };
    return { translator, calls };
}

function setHead(title: string, ogTitle?: string): HTMLMetaElement | null
{
    document.title = title;
    document.head.querySelectorAll('meta[property="og:title"]').forEach(node => node.remove());
    if (ogTitle === undefined) return null;

    const meta = document.createElement('meta');
    meta.setAttribute('property', 'og:title');
    meta.setAttribute('content', ogTitle);
    document.head.appendChild(meta);
    return meta;
}

describe('HeadTitleTranslator', () =>
{
    it('translates document.title and a matching og:title in one resolution', async () =>
    {
        const og = setHead('Weekly schedule', 'Weekly schedule');
        const { translator, calls } = fakeTranslator({ 'Weekly schedule': 'Weekly schedule (PL)' });

        await new HeadTitleTranslator(document).translate(translator);

        expect(document.title).toBe('Weekly schedule (PL)');
        expect(og?.getAttribute('content')).toBe('Weekly schedule (PL)');
        // The og:title mirrors the title, so it reuses that one resolution.
        expect(calls).toEqual([[{ source: 'Weekly schedule' }]]);
    });

    it('translates an og:title that differs from the page title on its own', async () =>
    {
        const og = setHead('Weekly schedule', 'Notebook bundle HARBOR');
        const { translator, calls } = fakeTranslator({
            'Weekly schedule': 'Weekly schedule (PL)',
            'Notebook bundle HARBOR': 'Zestaw notatników HARBOR',
        });

        await new HeadTitleTranslator(document).translate(translator);

        expect(document.title).toBe('Weekly schedule (PL)');
        expect(og?.getAttribute('content')).toBe('Zestaw notatników HARBOR');
        expect(calls).toEqual([[{ source: 'Weekly schedule' }, { source: 'Notebook bundle HARBOR' }]]);
    });

    it('keeps the source title when nothing is translated yet', async () =>
    {
        setHead('Weekly schedule');
        const { translator } = fakeTranslator({});

        await new HeadTitleTranslator(document).translate(translator);

        expect(document.title).toBe('Weekly schedule');
    });

    it('restores the original title and og:title on a switch back to source', async () =>
    {
        const og = setHead('Weekly schedule', 'Weekly schedule');
        const { translator } = fakeTranslator({ 'Weekly schedule': 'Weekly schedule (PL)' });
        const controller = new HeadTitleTranslator(document);

        await controller.translate(translator);
        expect(document.title).toBe('Weekly schedule (PL)');

        controller.restore();
        expect(document.title).toBe('Weekly schedule');
        expect(og?.getAttribute('content')).toBe('Weekly schedule');
    });

    it('always translates from the pristine source across successive switches', async () =>
    {
        setHead('Weekly schedule');
        const { translator, calls } = fakeTranslator({ 'Weekly schedule': 'Weekly schedule (PL)' });
        const controller = new HeadTitleTranslator(document);

        await controller.translate(translator); // first switch writes the translation
        await controller.translate(translator); // second switch must re-read the source, not the translated title

        expect(document.title).toBe('Weekly schedule (PL)');
        expect(calls).toEqual([[{ source: 'Weekly schedule' }], [{ source: 'Weekly schedule' }]]);
    });

    it('does nothing when restore runs before any translation', () =>
    {
        setHead('Weekly schedule');
        expect(() => new HeadTitleTranslator(document).restore()).not.toThrow();
        expect(document.title).toBe('Weekly schedule');
    });
});

describe('HeadTitleTranslator with a server-named source title', () =>
{
    afterEach(() =>
    {
        document.head.querySelectorAll('meta[name^="translatewp-source"]').forEach(node => node.remove());
    });

    it('translates from the source title the server named, not from the translated title it printed', async () =>
    {
        setHead('Chi siamo - Harbor Dance Studio', 'Chi siamo - Harbor Dance Studio');
        const meta = document.createElement('meta');
        meta.setAttribute('name', 'translatewp-source-title');
        meta.setAttribute('content', 'Par mums - Harbor Dance Studio');
        document.head.appendChild(meta);
        const { translator, calls } = fakeTranslator({ 'Par mums - Harbor Dance Studio': 'About us - Harbor Dance Studio' });

        const subject = new HeadTitleTranslator();
        await subject.translate(translator);

        expect(calls).toEqual([[{ source: 'Par mums - Harbor Dance Studio' }]]);
        expect(document.title).toBe('About us - Harbor Dance Studio');
        expect(document.querySelector('meta[property="og:title"]')?.getAttribute('content')).toBe('About us - Harbor Dance Studio');

        subject.restore();
        expect(document.title).toBe('Par mums - Harbor Dance Studio');
    });
});

describe('HeadTitleTranslator on a switch that yields no translation', () =>
{
    it('puts the source title back when the previous language had been written by the runtime', async () =>
    {
        setHead('Page not found - Site');
        const subject = new HeadTitleTranslator();
        await subject.translate(fakeTranslator({ 'Page not found - Site': 'Pagina non trovata - Site' }).translator);
        expect(document.title).toBe('Pagina non trovata - Site');

        await subject.translate(fakeTranslator({}).translator);
        expect(document.title).toBe('Page not found - Site');
    });

    it('leaves a server-rendered title alone when the first switch yields nothing yet', async () =>
    {
        setHead('Pagina non trovata - Site');
        const meta = document.createElement('meta');
        meta.setAttribute('name', 'translatewp-source-title');
        meta.setAttribute('content', 'Page not found - Site');
        document.head.appendChild(meta);
        const subject = new HeadTitleTranslator();
        await subject.translate(fakeTranslator({}).translator);
        expect(document.title).toBe('Pagina non trovata - Site');
        meta.remove();
    });
});

describe('HeadTitleTranslator with the site name', () =>
{
    const asked = (calls: Call[]): string[][] => calls.map(call => call.map(request => request.source));

    it('asks for the product title and the site name apart, in one call, with the whole title only looked up', async () =>
    {
        const og = setHead('Garden Lantern – SwagDemo', 'Garden Lantern');
        const { translator, calls } = fakeTranslator({ 'Garden Lantern': 'Lanterna da giardino' });

        await new HeadTitleTranslator(document, 'SwagDemo').translate(translator);

        expect(document.title).toBe('Lanterna da giardino – SwagDemo');
        expect(og?.getAttribute('content')).toBe('Lanterna da giardino');
        expect(calls).toEqual([[
            { source: 'Garden Lantern', coveredBy: 'Garden Lantern – SwagDemo' },
            { source: 'SwagDemo', coveredBy: 'Garden Lantern – SwagDemo' },
            { source: 'Garden Lantern – SwagDemo', lookupOnly: true },
            { source: 'Garden Lantern' },
        ]]);
    });

    it('splits off a site name that leads the title', async () =>
    {
        setHead('SwagDemo | Just another shop');
        const { translator } = fakeTranslator({ 'Just another shop': 'Solo un altro negozio' });

        await new HeadTitleTranslator(document, 'SwagDemo').translate(translator);

        expect(document.title).toBe('SwagDemo | Solo un altro negozio');
    });

    it.each([
        ['Rock – Paper – Scissors – Site', 'Site', 'Rock – Paper – Scissors'],
        ['5 - 10 Tips – Site', 'Site', '5 - 10 Tips'],
        ['Mon – Fri Hours | Shop', 'Shop', 'Mon – Fri Hours'],
    ])('keeps the page part of %j whole', async (title, siteName, page) =>
    {
        setHead(title);
        const { translator, calls } = fakeTranslator({});

        await new HeadTitleTranslator(document, siteName).translate(translator);

        expect(asked(calls)).toEqual([[page, siteName, title]]);
    });

    it.each([
        ['A < B: Guide', 'Site'],
        ['Welcome to Our Site', 'Our Site'],
        ['Garden Lantern – SwagDemo', ''],
        ['SwagDemo', 'SwagDemo'],
    ])('asks for %j whole, as before, when the site name is not joined at an edge', async (title, siteName) =>
    {
        setHead(title);
        const { translator, calls } = fakeTranslator({});

        await new HeadTitleTranslator(document, siteName).translate(translator);

        expect(calls).toEqual([[{ source: title }]]);
    });

    it('uses the translation stored for the whole title when the page part has none', async () =>
    {
        setHead('Garden Lantern – SwagDemo');
        const { translator } = fakeTranslator({ 'Garden Lantern – SwagDemo': 'Lanterna da giardino – SwagDemo' });

        await new HeadTitleTranslator(document, 'SwagDemo').translate(translator);

        expect(document.title).toBe('Lanterna da giardino – SwagDemo');
    });

    it('prefers the page part over the translation stored for the whole title', async () =>
    {
        setHead('Garden Lantern – SwagDemo');
        const { translator } = fakeTranslator({
            'Garden Lantern': 'Lanterna da giardino',
            'Garden Lantern – SwagDemo': 'Lanterna – SwagDemo',
        });

        await new HeadTitleTranslator(document, 'SwagDemo').translate(translator);

        expect(document.title).toBe('Lanterna da giardino – SwagDemo');
    });
});
