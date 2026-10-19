import { useEffect, useRef, useState } from '@wordpress/element';

const normalizeHash = (hash: string) => hash.replace(/^#/, '').trim().toLowerCase();

// A hash is "tab" or "tab/section": the section is scrolled into view once the tab opens.
const splitHash = (): [string, string] =>
{
    const [tabName = '', section = ''] = normalizeHash(window.location.hash).split('/');
    return [tabName, section];
};

const getTabFromHash = (tabNames: string[], fallbackTabName: string) =>
{
    const [hashTabName] = splitHash();
    return tabNames.includes(hashTabName) ? hashTabName : fallbackTabName;
};

const setHash = (tabName: string) =>
{
    const url = new URL(window.location.href);
    url.hash = tabName;
    window.history.pushState(null, '', url.toString());
};

export const useTabHash = (tabNames: string[], fallbackTabName: string) =>
{
    const tabNamesRef = useRef(tabNames);
    tabNamesRef.current = tabNames;
    const fallbackRef = useRef(fallbackTabName);
    fallbackRef.current = fallbackTabName;

    const [activeTabName, setActiveTabName] = useState(() => getTabFromHash(tabNames, fallbackTabName));

    useEffect(() =>
    {
        const syncFromHash = () =>
            setActiveTabName(getTabFromHash(tabNamesRef.current, fallbackRef.current));

        window.addEventListener('hashchange', syncFromHash);
        syncFromHash();

        return () => window.removeEventListener('hashchange', syncFromHash);
    }, []);

    useEffect(() =>
    {
        const [hashTabName, section] = splitHash();
        if (section && hashTabName === activeTabName) {
            document.getElementById(section)?.scrollIntoView();
        }
    }, [activeTabName]);

    const onSelectTab = (tabName: string | null) =>
    {
        if (!tabName || !tabNamesRef.current.includes(tabName)) {
            return;
        }

        const [currentHash] = splitHash();
        if (currentHash === tabName) {
            return;
        }

        // A hash that names no tab of this page (a moved link such as #seo) is
        // left intact so the page that owns it can read and redirect it.
        if (currentHash !== '' && !tabNamesRef.current.includes(currentHash)) {
            return;
        }

        setHash(tabName);
        setActiveTabName(tabName);
    };

    return { activeTabName, onSelectTab };
};
