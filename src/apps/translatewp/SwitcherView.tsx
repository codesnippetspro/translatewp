import { switcherSurface } from '@/apps/translatewp/switcherSurface';
import type React from 'react';
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowUpRight, ChevronDown, Heart, Lock, Pencil, RefreshCw, Settings, Move } from 'lucide-react';

import { resolveSwitcherChrome, resolveSwitcherPosition } from '@/apps/translatewp/resolveSwitcherChrome';
import { resolveFrontendSlot } from '@/shared/host/frontend';
import { editMode, useEditMode } from '@/apps/translatewp/editMode';
import { dragHint, useDragHintDone } from '@/apps/translatewp/dragHint';
import { retranslatePage, updateAdminData } from '@/shared/api/admin';
import { managerCan, opensEditMode } from '@/apps/translatewp/managerAccess';
import { flagEmoji } from '@/shared/lib/languages/flagEmoji';
import type { LanguageItem, PublicSwitcherSettings } from '@/apps/translatewp/types';
import { Icon } from '@/shared/components/Icon';
import { getCountryFlagFromLocale } from '@/shared/lib/languages';
import { switcherLabel } from '@/shared/lib/languages/switcherLabel';
import { proNextStep } from '@/shared/lib/pro/proState';
import { LIST_SCREEN_MARGIN, resolveSideTabPosition, sideTabCss, sideTabHalf } from './sideTab';
import { useDropdownList } from '@/apps/translatewp/useDropdownList';

export type SwitcherViewProps = {
    languages: LanguageItem[];
    switcher: PublicSwitcherSettings;
    selectedLocale: string;
    isOpen: boolean;
    notice: string | null;
    /** A link shown after the notice, for a notice the reader can act on. */
    noticeAction?: { label: string; href: string };
    /** A language switch is in flight: the trigger shows it and reads as busy. */
    isTranslating?: boolean;
    /** Settings preview only: shows the loading ring without any real translation or busy state. */
    previewLoading?: boolean;
    /** inline: placed in the page; preview: the settings preview, drawn inside its box. */
    variant?: 'floating' | 'inline' | 'preview';
    onOpenChange: (open: boolean) => void;
    onLanguageSelect: (language: LanguageItem) => void;
    onDismissNotice: () => void;
	triggerBindings?: Pick<React.ButtonHTMLAttributes<HTMLButtonElement>,
		'onPointerDown' | 'onPointerMove' | 'onPointerUp' | 'onPointerCancel'
		| 'onLostPointerCapture' | 'onClickCapture' | 'title'> & {
		'data-drag-handle'?: boolean;
		'data-dragging'?: boolean;
	};
};

export function SwitcherView({
    languages,
    switcher,
    selectedLocale,
    isOpen,
    notice,
    noticeAction,
    isTranslating = false,
    previewLoading = false,
    variant = 'floating',
    onOpenChange,
    onLanguageSelect,
    onDismissNotice,
	triggerBindings,
}: SwitcherViewProps)
{
    const containerRef = useRef<HTMLDivElement>(null);
    // A signed-in administrator's menu carries the Pro editor
    // entries; visitors never resolve the slot and see the plain list.
    const can = managerCan();
    const canManage = opensEditMode(can);
    const AdminMenu = canManage ? resolveFrontendSlot('switcherAdminMenu') : null;
    const pageObject = window.TranslateWP?.context?.page_object ?? null;
    const [retranslateStep, setRetranslateStep] = useState<'idle' | 'confirm' | 'busy' | 'done' | 'failed'>('idle');
    const [retranslated, setRetranslated] = useState(0);
    const retranslateThisPage = async () =>
    {
        if (!pageObject || retranslateStep === 'busy') return;
        if (retranslateStep !== 'confirm')
        {
            setRetranslateStep('confirm');
            return;
        }
        setRetranslateStep('busy');
        try
        {
            const result = await retranslatePage({ objectType: pageObject.type, objectId: pageObject.id, url: pageObject.url });
            setRetranslated(result.requeued);
            setRetranslateStep('done');
        }
        catch { setRetranslateStep('failed'); }
    };
    const pro = window.TranslateWP?.context?.pro;
    const entitled = pro?.entitled === true || window.TranslateWP?.context?.orbital === true;
    // Branding stays on a Terrestrial site; the Pro toggle writes the switcher setting and applies at once.
    const [brandingHidden, setBrandingHidden] = useState(entitled && switcher?.hide_branding !== false);
    const [brandingSaving, setBrandingSaving] = useState(false);
    const toggleBranding = async () =>
    {
        if (!entitled || brandingSaving) return;
        const next = !brandingHidden;
        setBrandingHidden(next);
        setBrandingSaving(true);
        try { await updateAdminData('languages.switcher', { hide_branding: next }); }
        catch { setBrandingHidden(!next); }
        finally { setBrandingSaving(false); }
    };
    const upgradeUrl = pro?.upgrade_url || 'https://app.translatewp.ai/upgrade';
    const installUrl = pro?.install_url || '';
    // An entitled account is pushed to install or activate the
    // addon, never asked to buy again. Active Pro without its menu (an older
    // addon) still gets the activate path, never the upgrade.
    const proStep = proNextStep({
        connected: true,
        entitled,
        installed: pro?.installed === true,
        active: pro?.active === true,
        version: '',
        upgradeUrl,
        upgradeStage: null,
    }) ?? (entitled ? 'activate' : 'upgrade');
    const [lockedPrompt, setLockedPrompt] = useState<string | null>(null);
    const editing = useEditMode();
    const { listRef, more } = useDropdownList({ open: isOpen, languageCount: languages.length, editing, settings: switcher });
    // The sweep starts from a different angle on every mount.
    const sweepStart = useMemo(() => Math.floor(Math.random() * 360), []);
    const switcherType = switcher?.type === 'native_select' ? 'native_select' : 'dropdown';
    const selectedType = switcher?.selected_type ?? switcher?.type ?? 'dropdown';
    // A selected Side tab sits on a screen edge, so it offers no place in the page content.
    const placesInContent = selectedType !== 'side_tab';
    // A side tab only exists on a screen edge; placed in the page it is the dropdown.
    const sideTab = variant !== 'inline' && switcher?.type === 'side_tab';
    const tabPlace = resolveSideTabPosition(switcher ?? {}, 'desktop');
    const tabPlaceMobile = resolveSideTabPosition(switcher ?? {}, 'mobile');
    // A native select has no panel of its own, so a manager gets a
    // pencil button beside it that opens the same admin menu.
    const nativeAdmin = switcherType === 'native_select' && canManage;
    const showFlag = switcher?.show_flag !== false;
    const nativeNames = switcher?.native_names !== false;
    const regionalNames = switcher?.regional_names === 'short' ? 'short' : 'full';
    const iconStyle = switcher?.icon_style ?? 'rectangle';
    // The closed switcher shows the selected language's flag and
    // native name by default; "Flag only" and "Icon only" are the others.
    const selectedLanguage = languages.find((lang) => lang.locale === selectedLocale)
        ?? { locale: selectedLocale };
    const selectedLabel = switcherLabel(selectedLanguage, { nativeNames, regionalNames, flagShown: true });
    const selectedFlag = getCountryFlagFromLocale(selectedLocale, { iconStyle });
    const requestedTrigger = switcher?.trigger === 'flag' || switcher?.trigger === 'icon' ? switcher.trigger : 'name_flag';
    // A locale with no flag falls back to the icon for Flag only.
    const trigger = requestedTrigger === 'flag' && !selectedFlag ? 'icon' : requestedTrigger;

    const bgColor = switcher?.background_color || '#FFFFFF';
    const textColor = switcher?.text_color || '#393535';
    const borderColor = switcher?.border_color || '#E2E2E4';
    const surface = switcherSurface(switcher?.background_style, bgColor, textColor, borderColor);
    const highlightBg = switcher?.highlight_bg || '#0e3ecd';
    const highlightText = switcher?.highlight_text || '#FFFFFF';
    // The loading ring kept the highlight colour before it had its own; an
    // unset loading colour still reads as that highlight so a saved site looks unchanged.
    const loadingBorder = switcher?.loading_border_color || highlightBg;
    const { insetPx, iconSizePx, triggerSizePx } = resolveSwitcherChrome(switcher ?? {});
    const paddingPx = (triggerSizePx - iconSizePx) / 2;
    // The flag and name trigger is wider than it is tall, so the edge clamps
    // use its measured width; a square trigger keeps triggerSizePx.
    const triggerRef = useRef<HTMLButtonElement>(null);
    const [measuredWidthPx, setMeasuredWidthPx] = useState<number | null>(null);
    useLayoutEffect(() =>
    {
        const element = triggerRef.current;
        if (!element) {
            setMeasuredWidthPx(null);
            return undefined;
        }
        const measure = () => setMeasuredWidthPx(element.offsetWidth > 0 ? element.offsetWidth : null);
        measure();
        if (typeof ResizeObserver === 'undefined') {
            return undefined;
        }
        const observer = new ResizeObserver(measure);
        observer.observe(element);
        return () => observer.disconnect();
    }, [trigger, selectedLabel, switcherType]);
    const triggerWidthPx = measuredWidthPx ?? triggerSizePx;

	const floatingAnchor = variant === 'floating' && !sideTab;
	const position = resolveSwitcherPosition(switcher);
	const mobilePosition = resolveSwitcherPosition(switcher, 'mobile');
	const positionStyle = {
		'--twp-position-x': `${position.x}px`,
		'--twp-position-y': `${position.y}px`,
		'--twp-mobile-x': `${mobilePosition.x}px`,
		'--twp-mobile-y': `${mobilePosition.y}px`,
	} as React.CSSProperties;

    // One audience. Visitor or manager, everyone sees the same pending
    // line while a switch still has groups unserved; there is no error notice.
    // Re-gating the line later is a single change to this condition.
	const dragEnabled = triggerBindings?.['data-drag-handle'] === true;
	const placementHandle = variant === 'inline' && can.settings;
	// Once dismissed, the line gives way to a small round handle on the trigger's corner.
	const dragHintDismissed = useDragHintDone();
	const positioningNotice = dragEnabled && !dragHintDismissed
		? __('Drag the switcher to move it. It stays where you let go, on every page.', 'translate')
		: null;
    const visibleNotice = notice ?? positioningNotice;

    useEffect(() =>
    {
        const handlePointerDown = (event: MouseEvent | TouchEvent) =>
        {
            if (!isOpen || !containerRef.current) return;
            if (!containerRef.current.contains(event.target as Node))
            {
                onOpenChange(false);
            }
        };

        document.addEventListener('mousedown', handlePointerDown);
        document.addEventListener('touchstart', handlePointerDown);
        return () =>
        {
            document.removeEventListener('mousedown', handlePointerDown);
            document.removeEventListener('touchstart', handlePointerDown);
        };
    }, [isOpen, onOpenChange]);

    return (
        <>
            <style>
                { `
                .twp-container {
                    --twp-admin-bar-height: 0px;
                    position: fixed !important;
                    top: calc(var(--twp-admin-bar-height) + min(var(--twp-position-y), max(0px, 100% - var(--twp-admin-bar-height) - ${ triggerSizePx }px))) !important;
                    right: min(var(--twp-position-x), max(0px, 100% - ${ triggerWidthPx }px)) !important;
                    z-index: 999999 !important;
                    font-family: inherit !important;
                }
                .twp-container[data-anchor] {
                    /* Room between the switcher's own edge and the far side of the screen, where its list ends. */
                    --twp-list-room: calc(100vw - min(var(--twp-position-x), max(0px, 100vw - ${ triggerWidthPx }px)) - ${ LIST_SCREEN_MARGIN });
                }
                .twp-container[data-anchor$="left"] {
                    right: auto !important;
                    left: min(var(--twp-position-x), max(0px, 100% - ${ triggerWidthPx }px)) !important;
                }
                .twp-container[data-anchor^="bottom"] {
                    top: auto !important;
                    bottom: min(var(--twp-position-y), max(0px, 100% - var(--twp-admin-bar-height) - ${ triggerSizePx }px)) !important;
                }
                .twp-container[data-anchor$="left"] .twp-popover-stack {
                    left: 0 !important;
                    right: auto !important;
                    align-items: flex-start !important;
                }
                .twp-container[data-anchor^="bottom"] .twp-popover-stack {
                    top: auto !important;
                    bottom: 100% !important;
                    margin-top: 0 !important;
                    margin-bottom: 0.25rem !important;
                }
                .twp-trigger[data-drag-handle="true"] {
                    cursor: grab !important;
                    touch-action: none !important;
                    user-select: none !important;
                }
                .twp-trigger[data-dragging="true"] {
                    cursor: grabbing !important;
                }
                .twp-drag-dot {
                    position: absolute !important;
                    top: -11px !important;
                    right: -11px !important;
                    display: inline-flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    width: 22px !important;
                    height: 22px !important;
                    border-radius: 9999px !important;
                    border: 2px solid #FFFFFF !important;
                    background: ${ highlightBg } !important;
                    color: #FFFFFF !important;
                    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25) !important;
                    cursor: grab !important;
                    z-index: 2 !important;
                }
                .twp-drag-dot[data-twp-placement-only] {
                    display: none !important;
                }
                html.twp-placing .twp-drag-dot[data-twp-placement-only] {
                    display: inline-flex !important;
                }
                .twp-admin-switch {
                    display: inline-block !important;
                    margin-left: auto !important;
                    width: 28px !important;
                    height: 16px !important;
                    border-radius: 9999px !important;
                    background: #C9CDD3 !important;
                    border: 1px solid rgba(0, 0, 0, 0.18) !important;
                    box-sizing: border-box !important;
                    position: relative !important;
                    flex: none !important;
                }
                .twp-admin-switch[data-on="true"] {
                    background: ${ highlightBg } !important;
                    border-color: ${ highlightBg } !important;
                }
                /* On the highlighted row the solid track colour matches the row
                   background, so the track switches to a translucent-white fill with a
                   white outline to keep reading as a toggle. */
                .twp-admin-item:hover .twp-admin-switch,
                .twp-admin-item:focus-visible .twp-admin-switch,
                .twp-admin-item[aria-pressed="true"] .twp-admin-switch {
                    background: rgba(255, 255, 255, 0.25) !important;
                    border-color: rgba(255, 255, 255, 0.95) !important;
                }
                .twp-admin-item[aria-disabled="true"] {
                    cursor: not-allowed !important;
                }
                .twp-admin-switch[data-locked="true"] {
                    opacity: 0.9 !important;
                }
                .twp-admin-switch::after {
                    content: '' !important;
                    position: absolute !important;
                    top: 2px !important;
                    left: 2px !important;
                    width: 10px !important;
                    height: 10px !important;
                    border-radius: 9999px !important;
                    background: #FFFFFF !important;
                    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3) !important;
                    transition: transform 120ms ease !important;
                }
                .twp-admin-switch[data-on="true"]::after {
                    transform: translateX(12px) !important;
                }
                .twp-drag-dot svg {
                    width: 12px !important;
                    height: 12px !important;
                    fill: none !important;
                    stroke: currentColor !important;
                }
                body.admin-bar .twp-container {
                    /* WordPress sets --wp-admin--admin-bar--height on html in
                       recent versions; the pixel fallbacks (32px, 46px below
                       783px) cover versions that do not. */
                    --twp-admin-bar-height: var(--wp-admin--admin-bar--height, 32px);
                }
                @media screen and (max-width: 782px) {
                    body.admin-bar .twp-container {
                        --twp-admin-bar-height: var(--wp-admin--admin-bar--height, 46px);
                    }
                }
                @media screen and (max-width: 600px) {
                    /* Below 600 pixels WordPress lets the admin bar scroll away
                       with the page, so a fixed switcher leaves no room for it. */
                    body.admin-bar .twp-container {
                        --twp-admin-bar-height: 0px;
                    }
                }
                @media screen and (max-width: 782px) {
                    /* A phone-width screen has its own position: the one the
                       owner dragged it to there, or the bottom corner. */
                    .twp-container[data-mobile-anchor] {
                        --twp-position-x: var(--twp-mobile-x) !important;
                        --twp-position-y: var(--twp-mobile-y) !important;
                    }
                    .twp-container[data-mobile-anchor$="right"] {
                        left: auto !important;
                        right: min(var(--twp-position-x), max(0px, 100% - ${ triggerWidthPx }px)) !important;
                    }
                    .twp-container[data-mobile-anchor$="left"] {
                        right: auto !important;
                        left: min(var(--twp-position-x), max(0px, 100% - ${ triggerWidthPx }px)) !important;
                    }
                    .twp-container[data-mobile-anchor^="top"] {
                        bottom: auto !important;
                        top: calc(var(--twp-admin-bar-height) + min(var(--twp-position-y), max(0px, 100% - var(--twp-admin-bar-height) - ${ triggerSizePx }px))) !important;
                    }
                    .twp-container[data-mobile-anchor^="bottom"] {
                        top: auto !important;
                        bottom: min(var(--twp-position-y), max(0px, 100% - var(--twp-admin-bar-height) - ${ triggerSizePx }px)) !important;
                    }
                    .twp-container[data-mobile-anchor$="right"] .twp-popover-stack {
                        left: auto !important;
                        right: 0 !important;
                        align-items: flex-end !important;
                    }
                    .twp-container[data-mobile-anchor$="left"] .twp-popover-stack {
                        left: 0 !important;
                        right: auto !important;
                        align-items: flex-start !important;
                    }
                    .twp-container[data-mobile-anchor^="bottom"] .twp-popover-stack {
                        top: auto !important;
                        bottom: 100% !important;
                        margin-top: 0 !important;
                        margin-bottom: 0.25rem !important;
                    }
                    .twp-container[data-mobile-anchor^="top"] .twp-popover-stack {
                        bottom: auto !important;
                        top: 100% !important;
                        margin-bottom: 0 !important;
                        margin-top: 0.25rem !important;
                    }
                }
                .twp-inline {
                    position: relative !important;
                    display: inline-block !important;
                    z-index: 999999 !important;
                    font-family: inherit !important;
                }
                .twp-inline .twp-popover-stack {
                    position: absolute !important;
                    top: 100% !important;
                    left: 0 !important;
                    right: auto !important;
                    align-items: flex-start !important;
                }
                .twp-trigger {
                    display: flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    width: ${ triggerSizePx }px !important;
                    height: ${ triggerSizePx }px !important;
                    padding: 0 !important;
                    ${ surface.closed }
                    border-radius: 0.5rem !important;
                    cursor: pointer !important;
                    transition: background-color 200ms, color 200ms !important;
                    color: ${ textColor } !important;
                }
                .twp-trigger svg {
                    width: ${ iconSizePx }px !important;
                    height: ${ iconSizePx }px !important;
                }
                .twp-trigger svg:not([data-twp-mark]) path {
                    fill: currentColor !important;
                }
                .twp-trigger svg[data-twp-mark] path[stroke] {
                    fill: none !important;
                    stroke: currentColor !important;
                }
                .twp-trigger svg[data-twp-mark] path:not([stroke]) {
                    fill: currentColor !important;
                }
                .twp-trigger[data-trigger="name_flag"] {
                    width: auto !important;
                    min-width: ${ triggerSizePx }px !important;
                    gap: 0.5rem !important;
                    padding: 0 ${ paddingPx }px !important;
                    font-size: 0.875rem !important;
                    line-height: 1.25rem !important;
                    white-space: nowrap !important;
                    max-width: calc(100vw - 2 * ${ insetPx }px) !important;
                }
                .twp-trigger .twp-trigger-label {
                    min-width: 0 !important;
                    overflow: hidden !important;
                    text-overflow: ellipsis !important;
                }
                .twp-trigger .twp-trigger-caret {
                    width: 1rem !important;
                    height: 1rem !important;
                    flex-shrink: 0 !important;
                }
                .twp-trigger .twp-trigger-caret path {
                    fill: none !important;
                }
                .twp-trigger[aria-expanded="true"] {
                    background-color: ${ highlightBg } !important;
                    color: ${ highlightText } !important;
                }
                /* The ring sweeps around the trigger all the time, invisible; a
                   switch in flight fades its colour in, and it fades out again
                   once the page has caught up. Each trigger starts its sweep
                   from its own angle. */
                @property --twp-sweep {
                    syntax: "<angle>";
                    inherits: false;
                    initial-value: 0deg;
                }
                .twp-trigger {
                    position: relative !important;
                }
                .twp-trigger::after {
                    content: "" !important;
                    position: absolute !important;
                    inset: -3px !important;
                    padding: 2px !important;
                    border-radius: calc(0.5rem + 3px) !important;
                    background: conic-gradient(from calc(var(--twp-sweep-start, 0deg) + var(--twp-sweep)), transparent 0deg, transparent 180deg, ${ loadingBorder } 300deg, transparent 360deg) !important;
                    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0) !important;
                    -webkit-mask-composite: xor !important;
                    mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0) !important;
                    mask-composite: exclude !important;
                    animation: twp-translating-sweep 1.4s linear infinite !important;
                    opacity: 0 !important;
                    transition: opacity 500ms ease !important;
                    pointer-events: none !important;
                }
                .twp-trigger[data-translating="true"]::after {
                    opacity: 1 !important;
                }
                @keyframes twp-translating-sweep {
                    to { --twp-sweep: 360deg; }
                }
                @media (prefers-reduced-motion: reduce) {
                    .twp-trigger::after {
                        animation: none !important;
                        background: ${ loadingBorder } !important;
                    }
                    .twp-trigger[data-translating="true"]::after {
                        opacity: 0.5 !important;
                    }
                }
                .twp-popover-stack {
                    position: absolute !important;
                    top: 100% !important;
                    right: 0 !important;
                    margin-top: 0.25rem !important;
                    display: flex !important;
                    flex-direction: column !important;
                    align-items: flex-end !important;
                    gap: 0.25rem !important;
					transition: opacity 120ms ease-out !important;
                }
				.twp-container[data-dragging="true"] :is(.twp-popover-stack, .twp-toast) {
					opacity: 0.6 !important;
				}
                .twp-dropdown {
                    position: relative !important;
                    display: flex !important;
                    flex-direction: column !important;
                    width: max-content !important;
                    min-width: min(12rem, var(--twp-list-room, 12rem)) !important;
                    max-width: min(20rem, var(--twp-list-room, calc(100vw - 2 * ${ insetPx }px))) !important;
                    border-radius: 0.5rem !important;
                    overflow: hidden !important;
                    box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1) !important;
                    ${ surface.panel }
                    color: ${ textColor } !important;
                    font-family: inherit !important;
                }
				.twp-dropdown[data-editing="true"] {
					min-width: min(15rem, var(--twp-list-room, 15rem)) !important;
				}
                .twp-dropdown-scroll {
                    position: relative !important;
                    min-height: 0 !important;
                }
                .twp-dropdown-list {
                    overflow-x: hidden !important;
                    overflow-y: auto !important;
                    overscroll-behavior: contain !important;
                    max-height: var(--twp-list-max-height, calc(100dvh - 5rem)) !important;
                    --twp-fade-top: 0px;
                    --twp-fade-bottom: 0px;
                    mask-image: linear-gradient(to bottom, transparent, #000 var(--twp-fade-top), #000 calc(100% - var(--twp-fade-bottom)), transparent) !important;
                }
                .twp-dropdown-scroll[data-more-above="true"] .twp-dropdown-list {
                    --twp-fade-top: 12px;
                }
                .twp-dropdown-scroll[data-more-below="true"] .twp-dropdown-list {
                    --twp-fade-bottom: 12px;
                }
                .twp-scroll-cue {
                    position: absolute !important;
                    right: 0.25rem !important;
                    display: flex !important;
                    pointer-events: none !important;
                    color: ${ textColor } !important;
                }
                .twp-scroll-cue-up {
                    top: 0 !important;
                    transform: rotate(180deg) !important;
                }
                .twp-scroll-cue-down {
                    bottom: 0 !important;
                }
                .twp-dropdown-item {
                    display: flex !important;
                    align-items: center !important;
                    gap: 0.5rem !important;
                    width: 100% !important;
                    padding: 0.625rem 0.75rem !important;
                    text-align: left !important;
                    font-size: 0.875rem !important;
					line-height: 1.25rem !important;
                    font-family: inherit !important;
                    cursor: pointer !important;
                    transition: background-color 200ms, color 200ms !important;
                    border: 0 !important;
                    background: none !important;
                    color: ${ textColor } !important;
                    box-sizing: border-box !important;
                }
                .twp-dropdown-item:hover {
                    background-color: ${ highlightBg } !important;
                    color: ${ highlightText } !important;
                }
                .twp-dropdown-item[data-selected="true"] {
                    background-color: ${ highlightBg } !important;
                    color: ${ highlightText } !important;
                }
                .twp-flag-wrap {
                    display: flex !important;
                    flex-shrink: 0 !important;
                }
				.twp-item-icon {
					display: flex !important;
					align-items: center !important;
					justify-content: center !important;
					flex: 0 0 2rem !important;
					width: 2rem !important;
				}
				.twp-admin-item > svg {
					flex: 0 0 auto !important;
					margin-right: 0.5625rem !important;
					margin-left: 0.5625rem !important;
				}
                .twp-dropdown-divider {
                    border: 0 !important;
                    border-top: 1px solid #E2E2E4 !important;
                    margin: 0 !important;
                }
                .twp-admin-item {
                    display: flex !important;
                    align-items: center !important;
                    gap: 0.5rem !important;
                    width: 100% !important;
                    padding: 0.625rem 0.75rem !important;
                    text-align: left !important;
                    font-size: 0.875rem !important;
					line-height: 1.25rem !important;
                    font-family: inherit !important;
                    cursor: pointer !important;
                    border: 0 !important;
                    background: none !important;
                    color: ${ textColor } !important;
					text-decoration: none !important;
					white-space: nowrap !important;
                    opacity: 0.85 !important;
                    box-sizing: border-box !important;
                }
                .twp-admin-item:hover, .twp-admin-item:focus-visible, .twp-admin-item[aria-pressed="true"] {
                    background-color: ${ highlightBg } !important;
                    color: ${ highlightText } !important;
                    opacity: 1 !important;
                }
                .twp-admin-item[disabled] {
                    opacity: 0.55 !important;
                    cursor: not-allowed !important;
                }
                .twp-admin-item[disabled]:hover {
                    background: none !important;
                    color: ${ textColor } !important;
                }
				.twp-admin-locked {
					opacity: 0.65 !important;
				}
				.twp-admin-locked:hover {
					opacity: 1 !important;
				}
				.twp-install-prompt {
					padding: 0.5rem 0.75rem 0.75rem !important;
					font-size: 0.8125rem !important;
					line-height: 1.25rem !important;
					color: ${ textColor } !important;
					white-space: normal !important;
				}
				.twp-install-prompt p {
					margin: 0 0 0.5rem !important;
				}
				.twp-install-prompt-action {
					display: inline-block !important;
					padding: 0.375rem 0.75rem !important;
					border-radius: 0.375rem !important;
					font-weight: 600 !important;
					text-decoration: none !important;
					background-color: ${ highlightBg } !important;
					color: ${ highlightText } !important;
				}
                .twp-pro-tag {
                    margin-left: auto !important;
                    padding: 0.1rem 0.4rem !important;
                    border-radius: 999px !important;
                    font-size: 0.625rem !important;
					line-height: 1rem !important;
                    font-weight: 700 !important;
                    letter-spacing: 0.04em !important;
                    background-color: ${ highlightBg } !important;
                    color: ${ highlightText } !important;
                }
				.twp-admin-item:hover .twp-pro-tag {
					background-color: ${ highlightText } !important;
					color: ${ highlightBg } !important;
				}
                .twp-promo {
                    display: flex !important;
                    align-items: center !important;
                    justify-content: center !important;
                    gap: 0.25rem !important;
                    padding: 0.5rem 0.75rem !important;
                    font-size: 0.75rem !important;
                    font-family: inherit !important;
                    text-decoration: none !important;
                    color: ${ textColor } !important;
                    opacity: 0.8 !important;
                    transition: opacity 200ms !important;
                }
                .twp-promo:hover,
                .twp-promo:focus-visible {
                    opacity: 1 !important;
                }
                /* The notice sits to the left of the trigger at the trigger's
                   height, never under the open language list below it. */
                .twp-toast {
                    position: absolute !important;
                    top: 0 !important;
                    right: 100% !important;
                    margin-right: 0.5rem !important;
                    z-index: 1 !important;
                    display: flex !important;
                    align-items: center !important;
                    gap: 0.5rem !important;
                    box-sizing: border-box !important;
                    /* A longer line wraps inside the bubble instead of running
                       out of it and over the switcher it points at. */
                    min-height: ${ triggerSizePx }px !important;
                    width: max-content !important;
                    max-width: min(20rem, calc(100vw - ${ triggerWidthPx }px - 2rem)) !important;
                    padding: 0.375rem 0.75rem !important;
                    white-space: normal !important;
                    overflow-wrap: anywhere !important;
                    ${ surface.panel }
                    border-radius: 0.5rem !important;
                    box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1) !important;
                    font-size: 0.8125rem !important;
                    line-height: 1.4 !important;
                    font-family: inherit !important;
                    color: ${ textColor } !important;
					transition: opacity 120ms ease-out !important;
                }
                .twp-container[data-anchor$="left"] .twp-toast {
                    left: 100% !important;
                    right: auto !important;
                    margin-right: 0 !important;
                    margin-left: 0.5rem !important;
                }
                .twp-inline .twp-toast {
                    right: 100% !important;
                    left: auto !important;
                }
                .twp-toast-action {
                    flex-shrink: 0 !important;
                    font-weight: 600 !important;
                    text-decoration: underline !important;
                    color: inherit !important;
                }
                .twp-toast-close {
                    flex-shrink: 0 !important;
                    padding: 0 !important;
                    border: 0 !important;
                    background: none !important;
                    cursor: pointer !important;
                    font-size: 1rem !important;
                    line-height: 1 !important;
                    color: inherit !important;
                    opacity: 0.6 !important;
                    transition: opacity 200ms !important;
                }
                .twp-toast-close:hover {
                    opacity: 1 !important;
                }
                /* A theme rule on select must not hide the switcher. */
                .twp-native-select {
                    display: inline-block !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                    appearance: none !important;
                    width: auto !important;
                    position: static !important;
                    padding: 0.5rem 2.25rem 0.5rem 0.75rem !important;
                    ${ surface.closed }
                    border-radius: 9999px !important;
                    cursor: pointer !important;
                    font-size: 0.875rem !important;
                    line-height: 1.25rem !important;
                    font-family: inherit !important;
                    background-image: url("data:image/svg+xml,${ encodeURIComponent(`<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='${ textColor }' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>`) }") !important;
                    background-repeat: no-repeat !important;
                    background-position: right 0.75rem center !important;
                    background-size: 1rem 1rem !important;
                    color: ${ textColor } !important;
                }
                .twp-native-admin {
                    display: inline-flex !important;
                    visibility: visible !important;
                    opacity: 1 !important;
                    align-items: center !important;
                    justify-content: center !important;
                    vertical-align: middle !important;
                    box-sizing: border-box !important;
                    width: 2.25rem !important;
                    height: 2.25rem !important;
                    margin: 0 0 0 0.25rem !important;
                    padding: 0 !important;
                    ${ surface.closed }
                    border-radius: 9999px !important;
                    cursor: pointer !important;
                    color: ${ textColor } !important;
                }
                ${ surface.fallback }
                ${ sideTab ? sideTabCss(triggerWidthPx) : '' }
                ` }
            </style>
            <div className={`translatewp-no-translate ${variant === 'inline' || (variant === 'preview' && !sideTab) ? 'twp-inline' : `twp-container${sideTab ? ' twp-side-tab' : ''}`}`} ref={containerRef}
				data-anchor={floatingAnchor ? position.anchor : undefined}
				data-mobile-anchor={floatingAnchor ? mobilePosition.anchor : undefined}
				data-edge={sideTab ? tabPlace.edge : undefined}
				data-mobile-edge={sideTab ? tabPlaceMobile.edge : undefined}
				data-half={sideTab ? sideTabHalf(tabPlace) : undefined}
				data-mobile-half={sideTab ? sideTabHalf(tabPlaceMobile) : undefined}
				data-preview={sideTab && variant === 'preview' ? 'true' : undefined}
				data-dragging={triggerBindings?.['data-dragging'] ? 'true' : undefined}
				style={sideTab
					? { '--twp-tab-share': String(tabPlace.offset), '--twp-tab-share-mobile': String(tabPlaceMobile.offset) } as React.CSSProperties
					: floatingAnchor ? positionStyle : undefined}>
                { switcherType === 'native_select' ? (
                    <>
                    <select
                        className="twp-native-select"
                        aria-label={ __('Language', 'translate') }
                        value={selectedLocale}
                        onChange={(event) =>
                        {
                            const lang = languages.find((l) => l.locale === event.target.value);
                            if (lang)
                            {
                                onLanguageSelect(lang);
                            }
                        }}
                    >
                        { languages.map((lang) => (
                            <option
                                key={lang.locale}
                                value={lang.locale}
                            >
                                { `${ showFlag && flagEmoji(lang.locale) !== '' ? `${flagEmoji(lang.locale)} ` : '' }${ switcherLabel(lang, { nativeNames, regionalNames, flagShown: showFlag }) }` }
                            </option>
                        )) }
                    </select>
                    { nativeAdmin && (
                        <button
                            type="button"
                            className="twp-native-admin"
                            aria-label={ __('Edit mode and admin menu', 'translate') }
                            aria-expanded={isOpen}
                            aria-haspopup="true"
                            data-twp-admin-menu
                            onClick={() => onOpenChange(!isOpen)}
                        >
                            <Pencil size={14} />
                        </button>
                    ) }
                    </>
                ) : (
                    <button
                        {...triggerBindings}
                        type="button"
                        aria-expanded={isOpen}
                        aria-haspopup="true"
                        aria-busy={isTranslating || undefined}
                        data-translating={isTranslating || previewLoading ? 'true' : undefined}
                        title={triggerBindings?.title ?? (isTranslating ? __('Translating…', 'translate') : undefined)}
                        className="twp-trigger"
                        ref={triggerRef}
                        data-trigger={trigger}
                        aria-label={trigger === 'name_flag'
                            ? undefined
                            /* translators: %s: the selected language, for example "English". */
                            : sprintf(__('Language: %s', 'translate'), selectedLabel)}
                        style={{ '--twp-sweep-start': `${sweepStart}deg` } as React.CSSProperties}
                        onClick={() => onOpenChange(!isOpen)}
                    >
                        { trigger === 'icon' ? <Icon tone="current" /> : (
                            <>
                                { selectedFlag && <span className="twp-flag-wrap">{ selectedFlag }</span> }
                                { trigger === 'name_flag' && (
                                    <>
                                        <span className="twp-trigger-label">{ selectedLabel }</span>
                                        <ChevronDown className="twp-trigger-caret" aria-hidden="true" />
                                    </>
                                ) }
                            </>
                        ) }
                    </button>
                ) }

                { (switcherType === 'dropdown' || nativeAdmin) && isOpen && (
                    <div className="twp-popover-stack">
						<div className="twp-dropdown" role={switcherType === 'dropdown' ? 'listbox' : undefined} data-editing={editing ? 'true' : undefined}>
                            { switcherType === 'dropdown' && (
                            <div className="twp-dropdown-scroll" data-more-above={more.above} data-more-below={more.below}>
                            <div className="twp-dropdown-list" ref={listRef}>
                                { languages.map((lang) =>
                                {
                                    const label = switcherLabel(lang, { nativeNames, regionalNames, flagShown: showFlag });
                                    const isSelected = selectedLocale === lang.locale;
                                    return (
                                        <button
                                            key={lang.locale}
                                            type="button"
                                            role="option"
                                            aria-selected={isSelected}
                                            data-selected={isSelected}
                                            className="twp-dropdown-item"
                                            onClick={() => onLanguageSelect(lang)}
										>
											<span className="twp-flag-wrap twp-item-icon">
												{ showFlag && getCountryFlagFromLocale(lang.locale, { iconStyle }) }
											</span>
                                            <span>{ label }</span>
                                        </button>
                                    );
                                }) }
                            </div>
                            {more.above && <span className="twp-scroll-cue twp-scroll-cue-up" aria-hidden="true"><ChevronDown size={14} /></span>}
                            {more.below && <span className="twp-scroll-cue twp-scroll-cue-down" aria-hidden="true"><ChevronDown size={14} /></span>}
                            </div>
                            ) }

                            { canManage && (
                                <>
                                    { switcherType === 'dropdown' && <hr className="twp-dropdown-divider" /> }
                                    <button
                                        type="button"
                                        className="twp-admin-item"
                                        aria-pressed={editing}
                                        data-twp-edit-mode
                                        onClick={() => editMode.toggle()}
                                    >
										<span className="twp-item-icon"><Pencil size={14} /></span>
                                        <span>{ editing ? __('Exit edit mode', 'translate') : __('Edit mode', 'translate') }</span>
                                    </button>
                                    { editing && (entitled && AdminMenu ? (
                                        <AdminMenu onClose={() => onOpenChange(false)} switcherType={selectedType} />
                                    ) : (
                                        <>
                                            { ([
                                                [can.edit, __('Edit translations', 'translate')],
                                                [can.settings && placesInContent, __('Switcher placement', 'translate')],
                                                [can.edit, __('Add to Lexicon', 'translate')],
											] as Array<[boolean, string]>).filter(([held]) => held).map(([, label]) => proStep === 'upgrade' ? (
												<a
													key={label}
													href={upgradeUrl}
													target="_blank"
													rel="noopener noreferrer"
													className="twp-admin-item twp-admin-locked"
													title={__('Available with Pro', 'translate')}
													data-twp-locked
												>
													<span className="twp-item-icon"><Lock size={14} /></span>
                                                    <span>{ label }</span>
                                                    <span className="twp-pro-tag">Pro</span>
												</a>
											) : (
												<button
													key={label}
													type="button"
													className="twp-admin-item twp-admin-locked"
													title={proStep === 'install'
														? __('Install Pro on this site to use this', 'translate')
														: __('Activate Pro on this site to use this', 'translate')}
													aria-expanded={lockedPrompt === label}
													data-twp-locked
													onClick={() => setLockedPrompt(lockedPrompt === label ? null : label)}
												>
													<span className="twp-item-icon"><Lock size={14} /></span>
                                                    <span>{ label }</span>
                                                    <span className="twp-pro-tag">Pro</span>
												</button>
                                            )) }
                                            { proStep !== 'upgrade' && lockedPrompt !== null && (
                                                <div className="twp-install-prompt" role="status" data-twp-install-prompt>
                                                    <p>
                                                        { proStep === 'install'
                                                            /* translators: %s: the locked Pro action, for example "Edit translations". */
                                                            ? sprintf(__('Your account includes Pro. Install it on this site to use %s.', 'translate'), lockedPrompt)
                                                            /* translators: %s: the locked Pro action, for example "Edit translations". */
                                                            : sprintf(__('Your account includes Pro. Activate it on this site to use %s.', 'translate'), lockedPrompt) }
                                                    </p>
                                                    { installUrl !== '' && (
                                                        <a href={installUrl} className="twp-install-prompt-action">
                                                            { proStep === 'install' ? __('Install now', 'translate') : __('Activate now', 'translate') }
                                                        </a>
                                                    ) }
                                                </div>
                                            ) }
                                        </>
                                    )) }
                                    { editing && window.TranslateWP?.settingsUrl && (
                                        <a href={window.TranslateWP.settingsUrl} className="twp-admin-item" data-twp-settings-link>
                                            <span className="twp-item-icon"><Settings size={14} /></span>
                                            <span>{ __('Switcher settings', 'translate') }</span>
                                        </a>
                                    ) }
                                    { editing && can.retranslate && pageObject && (
                                        <button
                                            type="button"
                                            className="twp-admin-item"
                                            disabled={retranslateStep === 'busy'}
                                            data-twp-retranslate-page
                                            onClick={() => { void retranslateThisPage(); }}
                                        >
                                            <span className="twp-item-icon"><RefreshCw size={14} /></span>
                                            <span>{ retranslateStep === 'confirm'
                                                ? __('Click again to retranslate this page in every language', 'translate')
                                                : retranslateStep === 'busy'
                                                    ? __('Retranslating this page…', 'translate')
                                                    : retranslateStep === 'done'
                                                        /* translators: %d: how many page translations were queued. */
                                                        ? sprintf(_n('%d page translation queued', '%d page translations queued', retranslated, 'translate'), retranslated)
                                                        : retranslateStep === 'failed'
                                                            ? __('Could not retranslate this page', 'translate')
                                                            : __('Retranslate this page', 'translate') }</span>
                                        </button>
                                    ) }
                                    { editing && can.settings && (
                                        <button
                                            type="button"
                                            className="twp-admin-item"
                                            role="switch"
                                            aria-checked={!brandingHidden}
                                            // A Terrestrial row carries aria-disabled, not the disabled
                                            // attribute, so the row keeps its hover highlight and tooltip.
                                            disabled={entitled && brandingSaving}
                                            aria-disabled={entitled ? undefined : true}
                                            title={entitled ? undefined : __('Always on with Terrestrial. Pro lets you turn it off.', 'translate')}
                                            data-twp-support-branding
                                            onClick={() => { void toggleBranding(); }}
                                        >
                                            <span className="twp-item-icon"><Heart size={14} /></span>
                                            <span>{ __('Support TranslateWP', 'translate') }</span>
                                            <span
                                                className="twp-admin-switch"
                                                aria-hidden="true"
                                                data-on={brandingHidden ? 'false' : 'true'}
                                                data-locked={entitled ? undefined : 'true'}
                                            />
                                        </button>
                                    ) }
                                </>
                            ) }

                            { ! brandingHidden && (
                                <>
                                    <hr className="twp-dropdown-divider" />
                                    <a
                                        href="https://translatewp.ai/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="twp-promo"
                                        aria-label={ __('by TranslateWP, opens in a new tab', 'translate') }
                                    >
                                        <span>by TranslateWP</span>
                                        <ArrowUpRight size={12} />
                                    </a>
                                </>
                            ) }
                        </div>
                    </div>
                ) }

                { visibleNotice && (
                    <div className="twp-toast" role="status" aria-live="polite">
                        <span>{ visibleNotice }</span>
                        { notice && noticeAction && (
                            <a className="twp-toast-action" href={noticeAction.href}>{ noticeAction.label }</a>
                        ) }
						{ (notice || positioningNotice) && (
							<button
								type="button"
								className="twp-toast-close"
								aria-label={ __('Dismiss message', 'translate') }
								onClick={notice ? onDismissNotice : () => dragHint.finish()}
							>
								&times;
							</button>
						) }
                    </div>
                ) }
                { ((dragEnabled && dragHintDismissed) || placementHandle) && (
                    <span
                        className="twp-drag-dot"
                        title={ __('Drag to reposition', 'translate') }
                        aria-hidden="true"
                        data-twp-drag-dot
                        data-twp-placement-only={placementHandle ? 'true' : undefined}
                        onPointerDown={(event) => { event.preventDefault(); triggerBindings?.onPointerDown?.(event as unknown as React.PointerEvent<HTMLButtonElement>); }}
                        onPointerMove={(event) => triggerBindings?.onPointerMove?.(event as unknown as React.PointerEvent<HTMLButtonElement>)}
                        onPointerUp={(event) => triggerBindings?.onPointerUp?.(event as unknown as React.PointerEvent<HTMLButtonElement>)}
                        onPointerCancel={(event) => triggerBindings?.onPointerCancel?.(event as unknown as React.PointerEvent<HTMLButtonElement>)}
                    >
                        <Move size={12} strokeWidth={2.5} />
                    </span>
                ) }
            </div>
        </>
    );
}
