// Languages written right to left, by primary subtag.
const RTL_PRIMARY_SUBTAGS = new Set(['ar', 'he', 'iw', 'fa', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb', 'prs', 'ks']);

export const isRtlLocale = (locale: string): boolean =>
{
    const primary = locale.toLowerCase().split(/[-_]/)[0];
    return RTL_PRIMARY_SUBTAGS.has(primary);
};

/** Writes the served language and its writing direction on the document. */
export const applyDocumentLanguage = (locale: string): void =>
{
    document.documentElement.lang = locale;
    if (isRtlLocale(locale))
    {
        document.documentElement.setAttribute('dir', 'rtl');
        return;
    }
    if (document.documentElement.getAttribute('dir') === 'rtl')
    {
        document.documentElement.setAttribute('dir', 'ltr');
    }
};
