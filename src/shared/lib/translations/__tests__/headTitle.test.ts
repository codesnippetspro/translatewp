import { HeadTitleTranslator, type SeoStringTranslator } from '@/shared/lib/translations/headTitle';

type Call = { source: string; contextKey: string };

function fakeTranslator(map: Record<string, string | null>): { translator: SeoStringTranslator; calls: Call[] }
{
    const calls: Call[] = [];
    const translator: SeoStringTranslator = {
        async translateSeoString(source, contextKey)
        {
            calls.push({ source, contextKey });
            return Object.prototype.hasOwnProperty.call(map, source) ? map[source] : null;
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
        expect(calls).toEqual([{ source: 'Weekly schedule', contextKey: 'seo:title' }]);
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
        expect(calls).toHaveLength(2);
    });

    it('asks for a differing og:title together with the page title, not after it', async () =>
    {
        const meta = setHead('About us – Acme', 'Our team');
        const asked: string[] = [];
        const answers: Array<(value: string | null) => void> = [];
        const translator: SeoStringTranslator = {
            translateSeoString: source => new Promise<string | null>(resolve =>
            {
                asked.push(source);
                answers.push(value => resolve(value));
            }),
        };

        const done = new HeadTitleTranslator(document).translate(translator);
        await Promise.resolve();

        // All parts are waiting at once, so they travel in one lookup and one miss report.
        expect(asked).toEqual(['About us', 'Acme', 'Our team']);

        answers[2]('Unser Team');
        answers[1](null);
        answers[0]('Über uns');
        await done;
        expect(document.title).toBe('Über uns – Acme');
        expect(meta?.getAttribute('content')).toBe('Unser Team');
    });

    it('translates the page title apart from the site name it is joined to', async () =>
    {
        const og = setHead('Garden Lantern – SwagDemo', 'Garden Lantern');
        const { translator, calls } = fakeTranslator({ 'Garden Lantern': 'Lanterna da giardino' });

        await new HeadTitleTranslator(document).translate(translator);

        expect(document.title).toBe('Lanterna da giardino – SwagDemo');
        expect(og?.getAttribute('content')).toBe('Lanterna da giardino');
        expect(calls.map(call => call.source)).toEqual(['Garden Lantern', 'SwagDemo']);
    });

    it('keeps every separator of a paged title as written', async () =>
    {
        setHead('Shop | Page 2 | SwagDemo');
        const { translator } = fakeTranslator({ Shop: 'Negozio', 'Page 2': 'Pagina 2' });

        await new HeadTitleTranslator(document).translate(translator);

        expect(document.title).toBe('Negozio | Pagina 2 | SwagDemo');
    });

    it('does not split a hyphenated word', async () =>
    {
        setHead('Wi-Fi setup');
        const { translator, calls } = fakeTranslator({ 'Wi-Fi setup': 'Configurazione Wi-Fi' });

        await new HeadTitleTranslator(document).translate(translator);

        expect(document.title).toBe('Configurazione Wi-Fi');
        expect(calls.map(call => call.source)).toEqual(['Wi-Fi setup']);
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
        expect(calls).toEqual([
            { source: 'Weekly schedule', contextKey: 'seo:title' },
            { source: 'Weekly schedule', contextKey: 'seo:title' },
        ]);
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
        const { translator, calls } = fakeTranslator({ 'Par mums': 'About us' });

        const subject = new HeadTitleTranslator();
        await subject.translate(translator);

        expect(calls.map(call => call.source)).toEqual(['Par mums', 'Harbor Dance Studio']);
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
        await subject.translate(fakeTranslator({ 'Page not found': 'Pagina non trovata' }).translator);
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
