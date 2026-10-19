// The server-rendered <title> and og:title are a crawler's source of truth and
// only catch up once the warm-up back-fill stores the title. For a person
// switching language in the browser, that lag is seconds of a source-language
// tab title. This translates document.title (and the og:title meta) on the same
// visit through the same group pipeline the body uses, so the tab title follows
// within the visit while the server path stays authoritative for view-source.

export const SEO_TITLE_CONTEXT_KEY = 'seo:title';
const OG_TITLE_SELECTOR = 'meta[property="og:title"]';
// A server that already printed a translated title names the source title
// here, so the runtime translates from the source and never sends a
// translation back as if it were source text.
export const SOURCE_TITLE_SELECTOR = 'meta[name="translatewp-source-title"]';
export const SOURCE_OG_TITLE_SELECTOR = 'meta[name="translatewp-source-og-title"]';

export type SeoStringTranslator = {
    translateSeoString(
        source: string,
        contextKey: string,
        options?: { signal?: AbortSignal }
    ): Promise<string | null>;
};

/**
 * Translates the document head's title surfaces for a visitor's language switch
 * and restores them when the visitor returns to the source language. The pristine
 * source values are captured once, before the first translation writes over them,
 * so every switch translates from the source and a restore returns the originals.
 */
export class HeadTitleTranslator
{
    readonly #doc: Document | undefined;
    #originalTitle: string | null = null;
    #originalOgTitle: string | null = null;
    #captured = false;
    #wroteTitle = false;

    constructor(doc: Document | undefined = globalThis.document)
    {
        this.#doc = doc;
    }

    async translate(translator: SeoStringTranslator, options: { signal?: AbortSignal } = {}): Promise<void>
    {
        const doc = this.#doc;
        if (!doc) return;

        this.#capture();

        const titleSource = (this.#originalTitle ?? '').trim();
        const og = doc.querySelector(OG_TITLE_SELECTOR);
        const ogSource = (this.#originalOgTitle ?? '').trim();

        // The og:title usually mirrors the page title and reuses its
        // translation. When it differs, both are asked for at once, so they
        // share one lookup and one miss report instead of a second round.
        const ogDiffers = Boolean(og && ogSource && ogSource !== titleSource);
        const [titleTranslation, ownOgTranslation] = await Promise.all([
            titleSource ? translator.translateSeoString(titleSource, SEO_TITLE_CONTEXT_KEY, options) : null,
            ogDiffers ? translator.translateSeoString(ogSource, SEO_TITLE_CONTEXT_KEY, options) : null,
        ]);

        if (options.signal?.aborted) return;

        // No translation means the source title is the right one (the target
        // reads the same, or nothing is stored yet). A title this runtime wrote
        // for the previous language must not outlive the switch; a title the
        // server rendered is left alone until a translation arrives.
        if (titleTranslation) doc.title = titleTranslation;
        else if (this.#wroteTitle && titleSource) doc.title = titleSource;
        this.#wroteTitle = Boolean(titleTranslation) || (this.#wroteTitle && Boolean(titleSource));
        if (og && ogSource)
        {
            const ogTranslation = ogDiffers ? ownOgTranslation : titleTranslation;
            if (ogTranslation) og.setAttribute('content', ogTranslation);
            else if (this.#wroteTitle) og.setAttribute('content', ogSource);
        }
    }

    restore(): void
    {
        const doc = this.#doc;
        if (!doc || !this.#captured) return;

        if (null !== this.#originalTitle) doc.title = this.#originalTitle;
        this.#wroteTitle = false;

        const og = doc.querySelector(OG_TITLE_SELECTOR);
        if (og && null !== this.#originalOgTitle) og.setAttribute('content', this.#originalOgTitle);
    }

    #capture(): void
    {
        if (this.#captured) return;
        const doc = this.#doc;
        if (!doc) return;

        const sourceTitle = doc.querySelector(SOURCE_TITLE_SELECTOR)?.getAttribute('content');
        this.#originalTitle = sourceTitle ?? doc.title ?? '';
        const og = doc.querySelector(OG_TITLE_SELECTOR);
        const sourceOgTitle = doc.querySelector(SOURCE_OG_TITLE_SELECTOR)?.getAttribute('content');
        this.#originalOgTitle = og ? (sourceOgTitle ?? sourceTitle ?? og.getAttribute('content') ?? '') : null;
        this.#captured = true;
    }
}
