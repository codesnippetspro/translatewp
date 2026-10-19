import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { ProStatus } from '@/shared/components/connection/ConnectionPanel';
import type { ProPanelState, UpgradeStage } from '@/shared/api/connect';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockGetConnectStatus = jest.fn();
const mockInstallPro = jest.fn();
const mockActivatePro = jest.fn();

const mockReloadPage = jest.fn();
jest.mock('@/shared/lib/reloadPage', () => ({ reloadPage: () => mockReloadPage() }));
jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: (...args: unknown[]) => mockGetConnectStatus(...args),
    installPro: (...args: unknown[]) => mockInstallPro(...args),
    activatePro: (...args: unknown[]) => mockActivatePro(...args),
    beginConnect: jest.fn(),
    disconnectSite: jest.fn(),
}));

const basePro: ProPanelState = {
    plan: 'pro',
    entitled: true,
    installed: false,
    active: false,
    installedVersion: null,
    latestVersion: '1.0.0',
    packagePending: false,
    upgrade: null,
    accountUrl: 'https://portal.example.test/account',
    updateAvailable: false,
    updateUrl: null,
    capabilities: { seo: true, custom_style: true, byok: true },
};

const render = async (pro: ProPanelState): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>
                <ProStatus pro={pro} accountUrl="https://app.example.com/sites/site-1" />
            </QueryClientProvider>
        );
    });
    return container;
};

const findButton = (container: HTMLElement, label: string): HTMLButtonElement | undefined =>
    Array.from(container.querySelectorAll('button'))
        .find(button => (button.textContent ?? '').trim() === label) as HTMLButtonElement | undefined;

describe('ProStatus after payment', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
        mockGetConnectStatus.mockResolvedValue({ connected: true, pro: basePro });
        mockInstallPro.mockReset();
    });

    afterEach(() =>
    {
        jest.useRealTimers();
        document.body.innerHTML = '';
    });

    it('says the payment is being confirmed and keeps checking while the Pro package is pending', async () =>
    {
        jest.useFakeTimers();
        const container = await render({ ...basePro, latestVersion: null, packagePending: true });

        expect(container.textContent).toContain('Payment received. We are confirming it, and Pro will be ready to install in a moment.');
        const callsBefore = mockGetConnectStatus.mock.calls.length;
        await act(async () =>
        {
            jest.advanceTimersByTime(16000);
        });
        expect(mockGetConnectStatus.mock.calls.length).toBeGreaterThan(callsBefore);
    });

    it('does not keep checking once Pro is ready to install', async () =>
    {
        jest.useFakeTimers();
        await render(basePro);
        await act(async () =>
        {
            jest.advanceTimersByTime(60000);
        });
        expect(mockGetConnectStatus).not.toHaveBeenCalled();
    });

    it('shows nothing and does not keep checking for an account that has not paid', async () =>
    {
        jest.useFakeTimers();
        const container = await render({ ...basePro, plan: 'free', entitled: false });

        expect(container.textContent).toBe('');
        await act(async () =>
        {
            jest.advanceTimersByTime(60000);
        });
        expect(mockGetConnectStatus).not.toHaveBeenCalled();
    });

    it('reloads the page after Pro installs', async () =>
    {
        mockReloadPage.mockClear();
        mockInstallPro.mockResolvedValue({ ok: true, message: 'TranslateWP Pro is installed and active.' });
        const container = await render(basePro);

        await act(async () => { findButton(container, 'Install Pro')?.click(); });

        expect(mockInstallPro).toHaveBeenCalledTimes(1);
        expect(mockReloadPage).toHaveBeenCalledTimes(1);
    });

    it('reloads the page after Pro activates', async () =>
    {
        mockReloadPage.mockClear();
        mockActivatePro.mockResolvedValue({ ok: true, message: 'TranslateWP Pro is active.' });
        const container = await render({ ...basePro, installed: true, active: false });

        await act(async () => { findButton(container, 'Activate Pro')?.click(); });

        expect(mockActivatePro).toHaveBeenCalledTimes(1);
        expect(mockReloadPage).toHaveBeenCalledTimes(1);
    });

    it('offers Try again and Open your account when Pro could not be prepared', async () =>
    {
        mockInstallPro.mockResolvedValue({
            ok: false,
            code: 'package_unavailable',
            message: 'Pro could not be prepared right now. Try again in a moment.',
        });
        const container = await render(basePro);

        await act(async () => { findButton(container, 'Install Pro')?.click(); });

        expect(container.textContent).toContain('Pro could not be prepared right now.');
        const tryAgain = findButton(container, 'Try again');
        expect(tryAgain).toBeDefined();
        const account = Array.from(container.querySelectorAll('a'))
            .find(link => (link.textContent ?? '').trim() === 'Open your account');
        expect(account?.getAttribute('href')).toBe('https://app.example.com/sites/site-1');
        expect(account?.getAttribute('target')).toBe('_blank');
        expect(account?.getAttribute('rel')).toBe('noopener noreferrer');

        await act(async () => { tryAgain?.click(); });
        expect(mockInstallPro).toHaveBeenCalledTimes(2);
    });
});


const TIMES = { checkoutStarted: '3:40 pm', checkoutCompleted: '3:42 pm', paymentReceived: '3:43 pm', confirmed: '3:44 pm', declined: '3:45 pm' };

const upgradeAt = (stage: UpgradeStage): ProPanelState['upgrade'] =>
{
    const order = ['checkout_started', 'checkout_completed', 'payment_received', 'confirmed'];
    const reached = order.indexOf(stage);
    return {
        stage,
        times: {
            checkoutStarted: TIMES.checkoutStarted,
            checkoutCompleted: reached >= 1 ? TIMES.checkoutCompleted : null,
            paymentReceived: reached >= 2 ? TIMES.paymentReceived : null,
            confirmed: reached >= 3 ? TIMES.confirmed : null,
            declined: stage === 'declined' ? TIMES.declined : null,
        },
    };
};

const steps = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('ol[aria-label="Upgrade progress"] > li')).map(step => ({
        state: step.getAttribute('data-state'),
        current: step.getAttribute('aria-current'),
        text: step.textContent ?? '',
    }));

describe('ProStatus upgrade timeline', () =>
{
    beforeEach(() =>
    {
        mockGetConnectStatus.mockReset();
        mockGetConnectStatus.mockResolvedValue({ connected: true, pro: basePro });
        Object.defineProperty(window, 'translatewpPro', { value: { upgradeUrl: 'https://example.com/wp-admin/admin-post.php?action=translatewp_start_upgrade' }, writable: true, configurable: true });
    });

    afterEach(() =>
    {
        jest.useRealTimers();
        document.body.innerHTML = '';
        Object.defineProperty(window, 'translatewpPro', { value: undefined, writable: true, configurable: true });
    });

    it.each([
        ['checkout_started', ['done', 'current', 'upcoming', 'upcoming'], 'Finish checkout in the TranslateWP portal. This page updates on its own.'],
        ['checkout_completed', ['done', 'done', 'current', 'upcoming'], 'Checkout completed. Waiting for the payment to arrive.'],
        ['payment_received', ['done', 'done', 'done', 'current'], 'Payment received. Turning on Pro for your account.'],
    ] as const)('shows the %s stage with its step states and line, and keeps checking', async (stage, states, line) =>
    {
        jest.useFakeTimers();
        const container = await render({ ...basePro, plan: 'free', entitled: false, upgrade: upgradeAt(stage) });

        const shown = steps(container);
        expect(shown.map(step => step.state)).toEqual(states);
        expect(shown.map(step => step.current)).toEqual(states.map(state => state === 'current' ? 'step' : null));
        expect(shown[0].text).toContain('Checkout started');
        expect(shown[0].text).toContain('3:40 pm');
        expect(shown[3].text).toContain('Pro confirmed');
        expect(container.textContent).toContain(line);
        expect(container.textContent).not.toContain('Install Pro');

        const callsBefore = mockGetConnectStatus.mock.calls.length;
        await act(async () => { jest.advanceTimersByTime(16000); });
        expect(mockGetConnectStatus.mock.calls.length).toBeGreaterThan(callsBefore);
    });

    it('labels each step state in text, not colour alone', async () =>
    {
        const container = await render({ ...basePro, plan: 'free', entitled: false, upgrade: upgradeAt('checkout_completed') });

        const shown = steps(container);
        expect(shown[0].text).toContain('Done');
        expect(shown[2].text).toContain('In progress');
        expect(shown[3].text).toContain('Not started');
    });

    it('shows a completed timeline above the Install Pro prompt once confirmed', async () =>
    {
        jest.useFakeTimers();
        const container = await render({ ...basePro, upgrade: upgradeAt('confirmed') });

        expect(steps(container).map(step => step.state)).toEqual(['done', 'done', 'done', 'done']);
        expect(steps(container)[3].text).toContain('3:44 pm');
        expect(container.textContent).toContain('Install Pro');
        await act(async () => { jest.advanceTimersByTime(60000); });
        expect(mockGetConnectStatus).not.toHaveBeenCalled();
    });

    it('shows a declined upgrade in the fourth step with a way to the account and to try again, and stops checking', async () =>
    {
        jest.useFakeTimers();
        const container = await render({ ...basePro, plan: 'free', entitled: false, upgrade: upgradeAt('declined') });

        const shown = steps(container);
        expect(shown[3].state).toBe('failed');
        expect(shown[3].text).toContain('Upgrade declined');
        expect(shown[3].text).toContain('3:45 pm');
        expect(shown[3].text).not.toContain('Pro confirmed');
        expect(container.textContent).toContain('Your upgrade was not confirmed. Open your account to check the payment or try again.');

        const links = Array.from(container.querySelectorAll('a'));
        expect(links.find(link => link.textContent === 'Open your account')?.getAttribute('href')).toBe('https://portal.example.test/account');
        expect(links.find(link => link.textContent === 'Try again')?.getAttribute('href')).toBe('https://example.com/wp-admin/admin-post.php?action=translatewp_start_upgrade');

        await act(async () => { jest.advanceTimersByTime(60000); });
        expect(mockGetConnectStatus).not.toHaveBeenCalled();
    });
});
