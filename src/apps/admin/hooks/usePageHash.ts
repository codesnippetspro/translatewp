import { useEffect, useRef, useState } from '@wordpress/element';

const normalizeHash = (hash: string) => hash.replace(/^#/, '').trim().toLowerCase();

// A hash is "page" or "page/section": the section is scrolled into view once the page opens.
const splitHash = (): [string, string] =>
{
    const [pageName = '', section = ''] = normalizeHash(window.location.hash).split('/');
    return [pageName, section];
};

const getPageFromHash = (pageNames: string[], fallbackPageName: string) =>
{
    const [hashPageName] = splitHash();
    return pageNames.includes(hashPageName) ? hashPageName : fallbackPageName;
};

const setHash = (pageName: string) =>
{
    const url = new URL(window.location.href);
    url.hash = pageName;
    window.history.pushState(null, '', url.toString());
    window.dispatchEvent(new Event('hashchange'));
};

export const usePageHash = (pageNames: string[], fallbackPageName: string) =>
{
    const pageNamesRef = useRef(pageNames);
    pageNamesRef.current = pageNames;
    const fallbackRef = useRef(fallbackPageName);
    fallbackRef.current = fallbackPageName;

    const [activePageName, setActivePageName] = useState(() => getPageFromHash(pageNames, fallbackPageName));

    useEffect(() =>
    {
        const syncFromHash = () =>
            setActivePageName(getPageFromHash(pageNamesRef.current, fallbackRef.current));

        window.addEventListener('hashchange', syncFromHash);
        syncFromHash();

        return () => window.removeEventListener('hashchange', syncFromHash);
    }, []);

    useEffect(() =>
    {
        const [hashPageName, section] = splitHash();
        if (section && hashPageName === activePageName) {
            document.getElementById(section)?.scrollIntoView();
        }
    }, [activePageName]);

    const onSelectPage = (pageName: string | null) =>
    {
        if (!pageName || !pageNamesRef.current.includes(pageName)) {
            return;
        }

        const [currentHash] = splitHash();
        if (currentHash === pageName) {
            return;
        }

        // A hash that names no known page (a moved link such as #seo) is
        // left intact so the page that owns it can read and redirect it.
        if (currentHash !== '' && !pageNamesRef.current.includes(currentHash)) {
            return;
        }

        setHash(pageName);
        setActivePageName(pageName);
    };

    return { activePageName, onSelectPage };
};
