import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { AdminSetupSwitcher } from '@/apps/translatewp/AdminSetupSwitcher';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const SETUP_URL = 'https://example.com/wp-admin/admin.php?page=translatewp';
const RECONNECT_URL = 'https://example.com/wp-admin/admin.php?page=translatewp-settings#connection';

const install = (canManage: boolean, notice?: { state: string; url: string }) =>
{
    Object.defineProperty(window, 'TranslateWP', {
        value: {
            canManage,
            context: { home_url: 'https://example.com', connected: false, setup_notice: notice },
            options: { languages: { list: [], switcher: { type: 'native_select', trigger: 'name_flag' } } },
        },
        configurable: true,
        writable: true,
    });
};

const roots: Array<ReturnType<typeof createRoot>> = [];

const render = (): HTMLElement =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    roots.push(root);
    act(() => { root.render(<AdminSetupSwitcher />); });
    return container;
};

afterEach(() =>
{
    for (const root of roots.splice(0)) act(() => { root.unmount(); });
    document.body.innerHTML = '';
    delete (window as { TranslateWP?: unknown }).TranslateWP;
});

describe('the switcher on a site that serves no visitors', () =>
{
    it('shows a manager the switcher with a message and a link to finish setup', () =>
    {
        install(true, { state: 'setup', url: SETUP_URL });
        const container = render();

        expect(container.querySelectorAll('.twp-container .twp-trigger')).toHaveLength(1);
        expect(container.querySelector('select')).toBeNull();
        const toast = container.querySelector('.twp-toast') as HTMLElement;
        expect(toast.textContent).toContain('TranslateWP is not set up yet.');
        const link = toast.querySelector('a') as HTMLAnchorElement;
        expect(link.textContent).toBe('Finish setup');
        expect(link.getAttribute('href')).toBe(SETUP_URL);
    });

    it('shows a manager on a disconnected site a link to reconnect', () =>
    {
        install(true, { state: 'reconnect', url: RECONNECT_URL });
        const container = render();

        const toast = container.querySelector('.twp-toast') as HTMLElement;
        expect(toast.textContent).toContain('TranslateWP is disconnected.');
        const link = toast.querySelector('a') as HTMLAnchorElement;
        expect(link.textContent).toBe('Reconnect');
        expect(link.getAttribute('href')).toBe(RECONNECT_URL);
    });

    it('brings a dismissed message back when the switcher is clicked', () =>
    {
        install(true, { state: 'setup', url: SETUP_URL });
        const container = render();

        act(() => { (container.querySelector('.twp-toast-close') as HTMLButtonElement).click(); });
        expect(container.querySelector('.twp-toast')).toBeNull();
        expect(container.querySelector('.twp-trigger')).not.toBeNull();

        act(() => { (container.querySelector('.twp-trigger') as HTMLButtonElement).click(); });
        expect(container.querySelector('.twp-toast a')?.getAttribute('href')).toBe(SETUP_URL);
        expect(container.querySelector('.twp-dropdown')).toBeNull();
    });

    it.each([
        ['a visitor', false, { state: 'setup', url: SETUP_URL }],
        ['a manager with no notice', true, undefined],
    ])('renders nothing for %s', (_label, canManage, notice) =>
    {
        install(canManage, notice);
        const container = render();

        expect(container.innerHTML).toBe('');
    });
});
