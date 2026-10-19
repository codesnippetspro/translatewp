import { type ExtractionOptions, EntryType } from '@/shared/lib/translations/constants';
import { DOMExtractor } from '@/shared/lib/translations/DOMExtractor';

const extractionCases: Array<{
    options: ExtractionOptions;
    expectedTypes: string[];
}> = [
    {
        options: { includeAlt: false, includeAttributes: false },
        expectedTypes: [EntryType.TEXT],
    },
    {
        options: { includeAlt: true, includeAttributes: false },
        expectedTypes: [EntryType.TEXT, EntryType.ALT],
    },
    {
        options: { includeAlt: false, includeAttributes: true },
        expectedTypes: [EntryType.TEXT, EntryType.ATTRIBUTE],
    },
    {
        options: { includeAlt: true, includeAttributes: true },
        expectedTypes: [EntryType.TEXT, EntryType.ALT, EntryType.ATTRIBUTE],
    },
];

describe('DOMExtractor extraction options', () =>
{
    it.each(extractionCases)('extracts the configured content types for $options', ({ options, expectedTypes }) =>
    {
        document.body.innerHTML = '<p title="Title">Text</p><img alt="Image">';

        const entries = new DOMExtractor().extract(document.body, options);

        expect(entries.map(entry => entry.type).sort()).toEqual([...expectedTypes].sort());
    });
});

describe('DOMExtractor administrator-only links', () =>
{
    it('never extracts the edit links WordPress shows only to editors', () =>
    {
        document.body.innerHTML = '<article><h1>Contact</h1><footer><span class="edit-link"><a class="post-edit-link" href="#">Edit <span class="screen-reader-text">Contact</span></a></span></footer>'
            + '<p>Nice post <a class="comment-edit-link" href="#">Edit</a></p></article>';

        const texts = new DOMExtractor({ noTranslateIds: ['wpadminbar'] }).extract(document.body).map(entry => entry.value.trim());

        expect(texts).toContain('Contact');
        expect(texts).toContain('Nice post');
        expect(texts).not.toContain('Edit');
        expect(texts.filter(text => text === 'Contact')).toHaveLength(1);
    });
});
