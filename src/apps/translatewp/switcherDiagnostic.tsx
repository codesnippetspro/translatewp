import { Component } from '@wordpress/element';
import type { ReactNode } from 'react';
import { opensEditMode } from '@/apps/translatewp/managerAccess';

export type SwitcherHiddenReason = 'type' | 'missing chunk' | 'missing host';

/**
 * A manager-only console line naming why the switcher shows nothing. Visitors
 * never see it; the page logs it at most once.
 */
let warned = false;

export function warnSwitcherHidden(reason: SwitcherHiddenReason, switcherType: string): void
{
    if (warned || !opensEditMode()) return;
    warned = true;
    const detail = {
        'type': `the ${switcherType} control is on the page but page CSS hides it`,
        'missing chunk': 'the admin switcher script failed to load, so the plain switcher is shown',
        'missing host': 'every [data-twp-switcher] placeholder on the page is hidden or detached, so the floating switcher is not shown',
    }[reason];
    console.warn(`TranslateWP: the language switcher is not visible (reason: ${reason}, type ${switcherType}): ${detail}.`);
}

/** Test hook: lets each test start with a fresh page. */
export function resetSwitcherWarning(): void
{
    warned = false;
}

const isShown = (element: Element | null): boolean =>
{
    if (!element || !element.isConnected) return false;
    for (let node: Element | null = element; node && node !== document.documentElement; node = node.parentElement)
    {
        const style = window.getComputedStyle(node);
        if (style.display === 'none' || style.opacity === '0') return false;
        if (node === element && (style.visibility === 'hidden' || style.visibility === 'collapse')) return false;
    }
    return true;
};

/**
 * Why nothing of the switcher is visible, or null when a control shows.
 * Computed styles only, so it reads the same with or without layout.
 */
export function diagnoseSwitcher(switcherType: 'dropdown' | 'native_select' | 'side_tab', hosts: HTMLElement[]): SwitcherHiddenReason | null
{
    const controls = Array.from(document.querySelectorAll('.twp-container, .twp-inline'))
        .map(root => root.querySelector('.twp-native-select, .twp-trigger'));
    if (controls.some(isShown)) return null;
    if (hosts.length && !hosts.some(isShown)) return 'missing host';
    return controls.length ? 'type' : null;
}

type BoundaryProps = { fallback: ReactNode; children: ReactNode; onError: () => void };

/**
 * The visitor path fails open. A manager's admin switcher lives in a
 * lazy chunk; when it fails to load, the plain switcher renders instead of
 * the whole root unmounting.
 */
export class AdminChunkBoundary extends Component<BoundaryProps, { failed: boolean }>
{
    state = { failed: false };

    static getDerivedStateFromError(): { failed: boolean }
    {
        return { failed: true };
    }

    componentDidCatch(): void
    {
        this.props.onError();
    }

    render(): ReactNode
    {
        return this.state.failed ? this.props.fallback : this.props.children;
    }
}
