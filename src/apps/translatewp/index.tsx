import { switchRollbackTarget, type SwitchOutcome } from '@/apps/translatewp/switchRollback';
import { applyDocumentLanguage } from '@/shared/lib/languages/textDirection';
import { localizeUrl, requestedLocaleOfPath } from '@/shared/lib/languages/languageUrl';
import { createPortal, lazy, Suspense, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { SwitcherView, type SwitcherViewProps } from '@/apps/translatewp/SwitcherView';
import { resolveRequestedLocale } from '@/apps/translatewp/localeResolution';
import { getTranslationOutcome } from '@/apps/translatewp/translationOutcome';
import type { LanguageItem } from '@/apps/translatewp/types';
import {
    getBrowserLanguagePreferenceOrder,
    matchBrowserToSiteLocale,
} from '@/shared/lib/languages';
import { CacheFirstTranslator } from '@/shared/lib/translations/CacheFirstTranslator';
import { HeadTitleTranslator } from '@/shared/lib/translations/headTitle';
import { HydrationStore } from '@/shared/lib/translations/hydrationStore';
import { scheduleHydration } from '@/shared/lib/translations/hydrationScheduler';
import { MissReportCoalescer } from '@/shared/lib/translations/MissReportCoalescer';
import { TranslationSession } from '@/shared/lib/translations/TranslationSession';
import { resolveFrontendSlot, updateFrontendPageState } from '@/shared/host/frontend';
import { addAction, removeAction } from '@wordpress/hooks';
import type { AppliedSegment } from '@/shared/lib/translations/segmentRegistry';
import { effectivePlacement, livePlacement, mountPlacement, resolvePlacementTarget, unmountPlacement } from '@/apps/translatewp/inlinePlacement';
import { AdminChunkBoundary, diagnoseSwitcher, warnSwitcherHidden } from '@/apps/translatewp/switcherDiagnostic';
import { managerCan, opensEditMode } from '@/apps/translatewp/managerAccess';

const AdminFloatingSwitcher = lazy(() => import('./AdminFloatingSwitcher'));

const switcherHosts = (): HTMLElement[] =>
    Array.from(document.querySelectorAll<HTMLElement>('[data-twp-switcher]'));

const normalizeLanguagesList = (list: unknown): LanguageItem[] =>
{
    if (Array.isArray(list))
    {
        return list as LanguageItem[];
    }
    if (list && typeof list === 'object')
    {
        return Object.values(list as Record<string, LanguageItem>);
    }
    return [];
};

const PENDING_NOTICE_MESSAGE = __('Translating this page for you, a few more seconds.', 'translate');
const PENDING_NOTICE_DELAY_MS = 8000;

export const TranslateWP = () =>
{
    const options = window.TranslateWP.options!;
    const { list: languagesList, switcher, seo = {} } = options.languages ?? {};
    const autoTranslate = options.auto_translate;
    const extractionOptions = options.extraction_options;
    const pendingNoticeMessage = (typeof switcher?.pending_message === 'string' && switcher.pending_message.trim() !== '')
        ? switcher.pending_message.trim()
        : PENDING_NOTICE_MESSAGE;

    const languages = useMemo(
        () => normalizeLanguagesList(languagesList).filter((l) => l.active),
        [languagesList]
    );
    const defaultLocale = languages.find((l) => l.default)?.locale ?? languages[0]?.locale ?? '';
    const [isOpen, setIsOpen] = useState(false);
    const [selectedLocale, setSelectedLocale] = useState(defaultLocale);
    const selectedLocaleRef = useRef(defaultLocale);
    selectedLocaleRef.current = selectedLocale;
    const translationSessionRef = useRef<TranslationSession | null>(null);
    const hydrationStoreRef = useRef<HydrationStore>(
        new HydrationStore(undefined, window.TranslateWP.context.cache_version, window.TranslateWP.context.cache_versions ?? {})
    );
    const missCoalescerRef = useRef<MissReportCoalescer>(new MissReportCoalescer());
    const headTitleRef = useRef<HeadTitleTranslator>(new HeadTitleTranslator());
    const pageUnloadingRef = useRef(false);
    useEffect(() =>
    {
        const markUnloading = () => { pageUnloadingRef.current = true; };
        window.addEventListener('pagehide', markUnloading);
        window.addEventListener('beforeunload', markUnloading);
        return () =>
        {
            window.removeEventListener('pagehide', markUnloading);
            window.removeEventListener('beforeunload', markUnloading);
        };
    }, []);
    const headTitleAbortRef = useRef<AbortController | null>(null);
    const automaticSelectionStartedRef = useRef(false);
    const [notice, setNotice] = useState<string | null>(null);
    // True from a language choice until that switch settles; a superseding
    // choice keeps it on and settles it in turn.
    const [isTranslating, setIsTranslating] = useState(false);
    const pendingNoticeTimerRef = useRef<number | null>(null);
    const switchTokenRef = useRef(0);

    function setActiveLocale(locale: string): void
    {
        setSelectedLocale(locale);
    }

    function clearPendingNoticeTimer(): void
    {
        if (pendingNoticeTimerRef.current)
        {
            window.clearTimeout(pendingNoticeTimerRef.current);
            pendingNoticeTimerRef.current = null;
        }
    }

    function hidePendingNotice(): void
    {
        clearPendingNoticeTimer();
        setNotice(null);
    }

    function getTranslationSession(): TranslationSession
    {
        if (!translationSessionRef.current)
        {
            translationSessionRef.current = new TranslationSession({
                root: document.body,
                sourceLang: defaultLocale,
                extractionOptions,
                noTranslateIds: ['wpadminbar'],
                // The public path is cache-first, the
                // browser job pipeline is retired from visitor translations.
                // missCoalescer is the page-level collector every chunk this
                // session translates feeds, so their misses report together.
                createTranslator: (sourceLang, targetLang) =>
                    new CacheFirstTranslator({
                        sourceLang,
                        targetLang,
                        store: hydrationStoreRef.current,
                        missCoalescer: missCoalescerRef.current,
                    }),
                onError: (error) =>
                {
                    // Visitors and managers see no error notice;
                    // the source text stays and the plugin keeps trying. The
                    // reason is logged to the console for managers debugging.
                    // A request the browser cut off because the visitor left
                    // the page is not a failure and is not logged.
                    if (pageUnloadingRef.current) return;
                    console.error('[TranslateWP] Dynamic translation failed:', error);
                },
                // A re-hydration flush clears the pending line
                // only when nothing on the page is still pending. While the
                // switch is polling its own groups, an unrelated flush finishing
                // must leave the line in place; it is bound to the page, not to
                // a single operation.
                onRehydrated: () =>
                {
                    if (translationSessionRef.current?.pendingTranslationCount() === 0)
                    {
                        hidePendingNotice();
                    }
                },
            });
        }

        return translationSessionRef.current;
    }

    // The address follows the language: /de/about/ for German, the plain
    // address for the default language. A visitor's own switch adds a history
    // entry; anything the page decides by itself replaces the current one, so
    // Back never lands on an address that moves the visitor forward again.
    function setLanguageAddress(locale: string | null, history: 'push' | 'replace'): void
    {
        const target = new URL(localizeUrl(window.location.href, locale ?? defaultLocale, languages, window.TranslateWP.context.home_url));
        const address = target.pathname + target.search + target.hash;
        if (address === window.location.pathname + window.location.search + window.location.hash)
        {
            return;
        }
        if (history === 'push')
        {
            window.history.pushState(null, '', address);
            return;
        }
        window.history.replaceState(window.history.state, '', address);
    }

    function resetToDefaultLanguage()
    {
        setLanguageAddress(null, 'push');
        clearRememberedLanguageSelection();
        setActiveLocale(defaultLocale);
        setNotice(null);
        applyDocumentLanguage(defaultLocale);
    }

    function clearRememberedLanguageSelection(): void
    {
        if (seo.remember_last_choice ?? true)
        {
            document.cookie = `translatewp_last_choice=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
        }
    }

    function beginLanguageSelection(locale: string, history: 'push' | 'replace'): void
    {
        setActiveLocale(locale);
        setIsOpen(false);
        setLanguageAddress(locale, history);
        applyDocumentLanguage(locale);
        persistLanguageSelection(locale);
    }

    function persistLanguageSelection(locale: string): void
    {
        const currentChoice = document.cookie
            .split('; ')
            .find((row) => row.startsWith('translatewp_last_choice='))
            ?.split('=')[1];
        if ((seo.remember_last_choice ?? true) && currentChoice !== locale)
        {
            const secure = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = `translatewp_last_choice=${locale}; path=/; SameSite=Lax${secure}; expires=${new Date(Date.now() + (seo.cookie_ttl ?? 30) * 24 * 60 * 60 * 1000).toUTCString()}`;
        }

        const homeUrl = new URL(localizeUrl(window.TranslateWP.context.home_url, locale, languages, window.TranslateWP.context.home_url));
        if ((seo.redirect_after_switch ?? false) && window.location.pathname.replace(/\/+$/, '') !== homeUrl.pathname.replace(/\/+$/, ''))
        {
            window.location.href = homeUrl.toString();
        }
    }

    function translatePageTitle(targetLang: string): void
    {
        headTitleAbortRef.current?.abort();
        const controller = new AbortController();
        headTitleAbortRef.current = controller;

        const translator = new CacheFirstTranslator({
            sourceLang: defaultLocale,
            targetLang,
            store: hydrationStoreRef.current,
            missCoalescer: missCoalescerRef.current,
        });

        void headTitleRef.current
            .translate(translator, { signal: controller.signal })
            .catch((error) =>
            {
                // No visitor notice; the source title stays and
                // the server path keeps serving crawlers. Logged for managers.
                if ((error as Error)?.name !== 'AbortError')
                {
                    console.warn('[TranslateWP] The page title could not be translated yet; it follows on a later view.', error);
                }
            });
    }

    function restorePageTitle(): void
    {
        headTitleAbortRef.current?.abort();
        headTitleAbortRef.current = null;
        headTitleRef.current.restore();
    }

    // A switch that served nothing must not leave the lang attribute, the
    // remembered choice and the address disagreeing with the content.
    function rollBackSwitchIfUnserved(outcome: SwitchOutcome | 'error', _previousLocale: string, newLocale: string, switchToken: number): void
    {
        const target = switchRollbackTarget(outcome, defaultLocale, newLocale);
        if (null === target || switchTokenRef.current !== switchToken)
        {
            return;
        }
        // The body is back to the source text; the title follows it.
        restorePageTitle();
        setActiveLocale(target);
        applyDocumentLanguage(target);
        if (target === defaultLocale)
        {
            setLanguageAddress(null, 'replace');
            clearRememberedLanguageSelection();
        }
        else
        {
            setLanguageAddress(target, 'replace');
            persistLanguageSelection(target);
        }
    }

    function translate(lang: LanguageItem, history: 'push' | 'replace' = 'push')
    {
        const newLocale = lang.locale;
        const previousLocale = selectedLocale;
        const switchToken = ++switchTokenRef.current;
        clearPendingNoticeTimer();

        if (newLocale === defaultLocale)
        {
            if (selectedLocale !== defaultLocale)
            {
                resetToDefaultLanguage();
                setIsOpen(false);
                restorePageTitle();
                setIsTranslating(false);

                void getTranslationSession().switchTo(defaultLocale)
                    .then(() =>
                    {
                        if (seo.redirect_after_switch ?? false)
                        {
                            window.location.href = window.TranslateWP.context.home_url;
                        }
                    })
                    .catch((error) =>
                    {
                        if ((error as Error)?.name !== 'AbortError')
                        {
                            console.error('[TranslateWP] Failed to restore the original language:', error);
                        }
                    });
            }
            return;
        }

        beginLanguageSelection(newLocale, history);
        setIsTranslating(true);

        // The pending line appears only after this
        // initial window, and only while the switch still has groups unserved;
        // a page that finishes translating within the window never shows it.
        pendingNoticeTimerRef.current = window.setTimeout(
            () =>
            {
                pendingNoticeTimerRef.current = null;
                if (switchTokenRef.current === switchToken)
                {
                    setNotice(pendingNoticeMessage);
                }
            },
            PENDING_NOTICE_DELAY_MS,
        );

        // Translate the tab title on this visit, in parallel with the body, so a
        // person sees a translated title within seconds; the server-rendered
        // title still follows for crawlers through the warm-up back-fill.
        translatePageTitle(newLocale);

        void getTranslationSession().switchTo(newLocale)
            .then((result) =>
            {
                if (switchTokenRef.current === switchToken)
                {
                    hidePendingNotice();
                    setIsTranslating(false);
                }

                const outcome = getTranslationOutcome({
                    failures: result.failures,
                    totalGroups: result.totalGroups,
                });
                if (outcome === 'partial' || outcome === 'failure')
                {
                    // Nobody sees an error notice; the source
                    // text stays and the plugin keeps polling on the next view.
                    console.warn('[TranslateWP] Some page content could not be translated; original text kept for affected entries.');
                }
                rollBackSwitchIfUnserved(outcome === 'pending' ? 'success' : outcome, previousLocale, newLocale, switchToken);
            })
            .catch((err) =>
            {
                // A superseding switch owns the pending line now, so only the
                // current switch clears it; an aborted switch settles silently.
                if (switchTokenRef.current === switchToken)
                {
                    hidePendingNotice();
                    setIsTranslating(false);
                }
                if ((err as Error)?.name === 'AbortError')
                {
                    return;
                }
                rollBackSwitchIfUnserved('error', previousLocale, newLocale, switchToken);

                console.warn('[TranslateWP] Translation failed; original text kept for affected entries.', err);
            });
    }

    // Back and Forward move between the addresses the switches pushed; the
    // page follows the address it lands on, in place, with no new entry.
    const translateRef = useRef(translate);
    translateRef.current = translate;
    useEffect(() =>
    {
        if (!languages.length) return;
        const follow = (): void =>
        {
            const locale = requestedLocaleOfPath(window.location.pathname, languages, window.TranslateWP.context.home_url) || defaultLocale;
            if (locale === selectedLocaleRef.current) return;
            const langItem = languages.find((l) => l.locale === locale);
            if (langItem) translateRef.current(langItem, 'replace');
        };
        window.addEventListener('popstate', follow);
        return () => window.removeEventListener('popstate', follow);
    }, [languages.length]);

    useEffect(() =>
    {
        if (automaticSelectionStartedRef.current || !languages.length || !window.TranslateWP.context.connected)
        {
            return;
        }

        automaticSelectionStartedRef.current = true;

        // The server names the language of a folder address; an old ?lang=
        // link it has not redirected (a cached page) is still honoured.
        const lang = window.TranslateWP.context.requested_locale
            || requestedLocaleOfPath(window.location.pathname, languages, window.TranslateWP.context.home_url)
            || new URL(window.location.href).searchParams.get('lang');
        const cookie = document.cookie.split('; ').find((row) => row.startsWith('translatewp_last_choice='));
        const localeFromCookie = cookie ? cookie.split('=')[1] : null;
        const resolution = resolveRequestedLocale(lang, localeFromCookie, languages, defaultLocale);
        if (resolution.staleCookie)
        {
            clearRememberedLanguageSelection();
        }
        if (resolution.locale)
        {
            const langItem = languages.find((l) => l.locale === resolution.locale);
            if (langItem)
            {
                translate(langItem, 'replace');
            }
            return;
        }

        if (autoTranslate)
        {
            const siteLocales = languages.map((l) => l.locale);
            const matched = matchBrowserToSiteLocale(
                getBrowserLanguagePreferenceOrder(),
                siteLocales,
            );
            if (matched && matched !== defaultLocale)
            {
                const langItem = languages.find((l) => l.locale === matched);
                if (langItem)
                {
                    translate(langItem, 'replace');
                }
            }
        }
    }, [languages.length]);

    useEffect(() =>
    {
        if (!languages.length)
        {
            return;
        }

        // Pre-hydrate translations into browser storage on idle
        // time so later visits and switches serve locally.
        return scheduleHydration({
            root: document.body,
            sourceLang: defaultLocale,
            activeLang: hydrationStoreRef.current.activeLanguage() ?? selectedLocale,
            configuredLangs: languages.map((l) => l.locale),
            extractionOptions,
            noTranslateIds: ['wpadminbar'],
            store: hydrationStoreRef.current,
        });
    }, [languages.length]);

    // The Pro bundle registers its slots after this app may have
    // rendered; a registration re-renders so the entries appear at once.
    const [, slotsRevision] = useState(0);
    useEffect(() =>
    {
        const bump = () => slotsRevision(value => value + 1);
        addAction('translatewp.frontend.slotsChanged', 'translatewp/frontend-slots', bump);
        return () => { removeAction('translatewp.frontend.slotsChanged', 'translatewp/frontend-slots'); };
    }, []);

    // The Pro front-end editor reads the page through the bridge.
    useEffect(() =>
    {
        updateFrontendPageState({
            hydrationStore: hydrationStoreRef.current,
            defaultLocale,
            selectedLocale,
            retranslate: async (segments: AppliedSegment[]) =>
            {
                const byLanguage = new Map<string, AppliedSegment[]>();
                for (const segment of segments)
                {
                    const list = byLanguage.get(segment.language) ?? [];
                    list.push(segment);
                    byLanguage.set(segment.language, list);
                }
                for (const [targetLang, list] of byLanguage)
                {
                    const translator = new CacheFirstTranslator({
                        sourceLang: defaultLocale,
                        targetLang,
                        store: hydrationStoreRef.current,
                        missCoalescer: missCoalescerRef.current,
                    });
                    const entries = list.flatMap(segment => segment.group.entries);
                    await translator.translateBatch(entries, {
                        preferRemote: true,
                        onEntryTranslated: entry => { entry.node.nodeType === Node.TEXT_NODE ? (entry.node.nodeValue = entry.value) : (entry.attributeName ? (entry.node as Element).setAttribute(entry.attributeName, entry.value) : undefined); },
                    });
                }
            },
        });
    }, [defaultLocale, selectedLocale]);

    // A saved inline placement hosts the switcher inside the document; the
    // floating widget stays hidden while it resolves. A page where the anchor
    // matches nothing follows the site's fallback: the floating widget, or no
    // switcher at all.
    const [placedHost, setPlacedHost] = useState<HTMLElement | null>(null);
    const [anchorMissing, setAnchorMissing] = useState(false);
    // A placement the Pro editor just saved moves the switcher at once.
    const [placementVersion, setPlacementVersion] = useState(livePlacement.version());
    useEffect(() => livePlacement.subscribe(() => setPlacementVersion(livePlacement.version())), []);
    useEffect(() =>
    {
        const live = livePlacement.current();
        const placement = live ? live.placement : effectivePlacement(window.TranslateWP.context.page_placement, switcher?.inline_placement);
        setAnchorMissing(false);
        const target = resolvePlacementTarget(placement);
        if (!target)
        {
            // A placement just applied whose spot is not on this page is
            // left to a reload; the missing spot is reported for the
            // placements the page loaded with only.
            if (placement && !live)
            {
                setAnchorMissing(true);
                void fetch(`${window.TranslateWP.context.translation_base_url}/anchor-missing`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    keepalive: true,
                    body: JSON.stringify({ url: window.location.href }),
                }).catch(() => undefined);
            }
            return;
        }
        const host = document.createElement('span');
        host.className = 'translatewp-no-translate twp-placed';
        host.setAttribute('data-twp-switcher', '');
        host.setAttribute('data-twp-placed', placement?.kind === 'zone' ? placement.zone : (placement?.position ?? 'anchor'));
        mountPlacement(target, host);
        setPlacedHost(host);
        return () =>
        {
            unmountPlacement(host);
            setPlacedHost(null);
        };
    // Runs on load and again for each placement the editor applies.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [placementVersion]);
    const hideFloating = anchorMissing && (switcher?.anchor_fallback ?? 'floating') === 'none';

    useEffect(() => () =>
    {
        translationSessionRef.current?.dispose();
        translationSessionRef.current = null;

        if (pendingNoticeTimerRef.current)
        {
            window.clearTimeout(pendingNoticeTimerRef.current);
        }
    }, []);

    const switcherType = switcher?.type === 'native_select' || switcher?.type === 'side_tab' ? switcher.type : 'dropdown';
    const connected = window.TranslateWP.context.connected;
    const rendersSwitcher = Boolean(connected && languages.length);

    // A manager whose switcher shows nothing gets one console line naming
    // why, once the lazy chunk and any placement have had time to settle.
    useEffect(() =>
    {
        if (!opensEditMode() || !rendersSwitcher) return;
        const timer = window.setTimeout(() =>
        {
            const hosts = switcherHosts();
            const reason = diagnoseSwitcher(switcherType, hosts);
            if (reason) warnSwitcherHidden(reason, switcherType);
        }, 3000);
        return () => window.clearTimeout(timer);
    }, [rendersSwitcher, switcherType]);

    if (!rendersSwitcher)
    {
        return null;
    }

    const placeholders = switcherHosts()
        .filter(placeholder => placeholder !== placedHost);
    if (placedHost) placeholders.push(placedHost);
    const FrontendEditor = managerCan().edit ? resolveFrontendSlot('frontendEditor') : null;

    const viewProps: SwitcherViewProps = {
        languages, switcher: switcher ?? {}, selectedLocale, isOpen, notice, isTranslating,
        onOpenChange: setIsOpen,
        onLanguageSelect: language => translate(language),
        onDismissNotice: hidePendingNotice,
    };

    return (
        <>
            {!placeholders.length && !hideFloating && (
                opensEditMode() && switcherType !== 'native_select' ? (
                    <AdminChunkBoundary
                        fallback={<SwitcherView {...viewProps} />}
                        onError={() => warnSwitcherHidden('missing chunk', switcherType)}
                    >
                        <Suspense fallback={<SwitcherView {...viewProps} />}>
                            <AdminFloatingSwitcher {...viewProps} />
                        </Suspense>
                    </AdminChunkBoundary>
                ) : <SwitcherView {...viewProps} />
            )}
            {placeholders.map((placeholder) => (
                createPortal(
                    <SwitcherView {...viewProps} variant="inline" />,
                    placeholder
                )
            ))}
            {FrontendEditor && (
                <FrontendEditor
                    languages={languages}
                    defaultLocale={defaultLocale}
                    selectedLocale={selectedLocale}
                    hasPagePlacement={Boolean(window.TranslateWP.context.page_placement)}
                    hasSitePlacement={Boolean(switcher?.inline_placement)}
                />
            )}
        </>
    );
};
