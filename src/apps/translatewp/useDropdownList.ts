import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import type { PublicSwitcherSettings } from '@/apps/translatewp/types';

const SCREEN_MARGIN = 8;
const LIST_GAP = 4;
const PEEK_SHARE = 0.6;
const SCROLL_TOLERANCE = 1;

function dropdownListHeight(rows: number[], available: number): number
{
    const total = rows.reduce((sum, height) => sum + height, 0);
    if (total <= available) return total;
    let used = 0;
    let previous = 0;
    for (const height of rows)
    {
        if (used + height >= available)
        {
            if (used + height * PEEK_SHARE <= available) return used + height * PEEK_SHARE;
            if (used > previous) return used - previous + previous * PEEK_SHARE;
            return Math.max(0, available);
        }
        used += height;
        previous = height;
    }
    return Math.max(0, available);
}

export function useDropdownList({ open, languageCount, editing, settings }: {
    open: boolean;
    languageCount: number;
    editing: boolean;
    settings: PublicSwitcherSettings;
})
{
    const listRef = useRef<HTMLDivElement>(null);
    const [more, setMore] = useState({ above: false, below: false });

    useLayoutEffect(() =>
    {
        const list = listRef.current;
        if (!open || !list) return;
        const panel = list.closest<HTMLElement>('.twp-dropdown');
        const stack = list.closest<HTMLElement>('.twp-popover-stack');
        const container = list.closest<HTMLElement>('.twp-container, .twp-inline');
        const trigger = container?.querySelector<HTMLElement>('.twp-trigger');
        if (!panel || !stack || !container || !trigger) return;

        const updateScroll = () =>
        {
            const next = {
                above: list.scrollTop > SCROLL_TOLERANCE,
                below: list.scrollHeight - list.clientHeight - list.scrollTop > SCROLL_TOLERANCE,
            };
            setMore(previous => previous.above === next.above && previous.below === next.below ? previous : next);
        };
        const measure = () =>
        {
            const rows = Array.from(list.children, row => row.getBoundingClientRect().height);
            if (!rows.some(height => height > 0)) return;
            const viewport = window.visualViewport;
            const screenTop = viewport?.offsetTop ?? 0;
            const screenBottom = screenTop + (viewport?.height ?? window.innerHeight);
            const adminBottom = document.getElementById('wpadminbar')?.getBoundingClientRect().bottom ?? 0;
            const top = Math.max(screenTop, adminBottom);
            const triggerBox = trigger.getBoundingClientRect();
            const sideTab = container.classList.contains('twp-side-tab');
            let up = triggerBox.top - top > screenBottom - triggerBox.bottom;
            if (!sideTab)
            {
                stack.style.setProperty('top', up ? 'auto' : '100%', 'important');
                stack.style.setProperty('bottom', up ? '100%' : 'auto', 'important');
                stack.style.setProperty('margin-top', up ? '0' : `${LIST_GAP}px`, 'important');
                stack.style.setProperty('margin-bottom', up ? `${LIST_GAP}px` : '0', 'important');
            }
            else
            {
                for (const property of ['top', 'bottom', 'margin-top', 'margin-bottom']) stack.style.removeProperty(property);
            }
            if (container.matches('.twp-inline:not([data-preview])'))
            {
                stack.style.removeProperty('left');
                stack.style.removeProperty('right');
                const screenLeft = viewport?.offsetLeft ?? 0;
                const screenRight = screenLeft + (viewport?.width ?? document.documentElement.clientWidth);
                const natural = panel.getBoundingClientRect();
                if (natural.left < screenLeft || natural.right > screenRight)
                {
                    const flipped = getComputedStyle(container).direction === 'rtl' ? triggerBox.left : triggerBox.right - natural.width;
                    const left = flipped >= screenLeft && flipped + natural.width <= screenRight
                        ? flipped
                        : Math.max(screenLeft + SCREEN_MARGIN, Math.min(flipped, screenRight - SCREEN_MARGIN - natural.width));
                    const offset = stack.getBoundingClientRect().left - container.getBoundingClientRect().left - container.clientLeft;
                    stack.style.setProperty('left', `${offset + left - natural.left}px`, 'important');
                    stack.style.setProperty('right', 'auto', 'important');
                }
            }
            const panelBox = panel.getBoundingClientRect();
            if (sideTab)
                up = panelBox.bottom <= triggerBox.bottom + SCROLL_TOLERANCE && panelBox.top < triggerBox.top;
            const room = up ? panelBox.bottom - top : screenBottom - panelBox.top;
            const chrome = panel.offsetHeight - list.offsetHeight;
            const available = Math.max(0, room - chrome - SCREEN_MARGIN);
            list.style.setProperty('--twp-list-max-height', `${dropdownListHeight(rows, available)}px`);
            updateScroll();
        };
        measure();
        const observer = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(measure);
        observer?.observe(panel);
        for (const row of Array.from(list.children)) observer?.observe(row);
        window.addEventListener('resize', measure);
        window.addEventListener('scroll', measure, true);
        window.visualViewport?.addEventListener('resize', measure);
        window.visualViewport?.addEventListener('scroll', measure);
        list.addEventListener('scroll', updateScroll);
        return () =>
        {
            observer?.disconnect();
            window.removeEventListener('resize', measure);
            window.removeEventListener('scroll', measure, true);
            window.visualViewport?.removeEventListener('resize', measure);
            window.visualViewport?.removeEventListener('scroll', measure);
            list.removeEventListener('scroll', updateScroll);
        };
    }, [open, languageCount, editing, settings]);

    return { listRef, more };
}
