import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { PRO_TAG_CLASS } from '@/shared/components/pro/ProBadge';
import type { ProState } from '@/shared/lib/pro/proState';
import { activatePro, installPro } from '@/shared/api/connect';
import { reloadPage } from '@/shared/lib/reloadPage';

jest.mock('@/shared/api/connect', () => ({ activatePro: jest.fn(), installPro: jest.fn() }));
jest.mock('@/shared/lib/reloadPage', () => ({ reloadPage: jest.fn() }));

const base: ProState = { connected: true, entitled: false, installed: false, active: false, version: '', upgradeUrl: 'https://app.translatewp.ai/upgrade', upgradeStage: null };

describe('quiet Pro markers', () =>
{
    let root: Root;
    let container: HTMLDivElement;
    beforeEach(() =>
    {
        (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;
        jest.resetAllMocks();
        container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
    });
    afterEach(() => { act(() => root.unmount()); container.remove(); });

    it('opens the Free upgrade prompt from a quiet, single-line tag', async () =>
    {
        await act(async () => root.render(<ProMarker state={base} />));
        const tag = container.querySelector('button')!;
        expect(tag.textContent).toBe('Pro');
        expect(tag.getAttribute('aria-label')).toBe('Upgrade to PRO');
        expect(tag.className).toContain(PRO_TAG_CLASS);
        expect(tag.className).not.toContain('is-primary');
        await act(async () => tag.click());
        expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Unlock this feature');
        expect(jest.mocked(installPro)).not.toHaveBeenCalled();
        expect(jest.mocked(activatePro)).not.toHaveBeenCalled();
    });

    it.each(['install', 'activate'] as const)('names the %s action and prevents repeated clicks while it runs', async step =>
    {
        const action = jest.mocked(step === 'install' ? installPro : activatePro);
        let finish!: (value: Awaited<ReturnType<typeof installPro>>) => void;
        action.mockImplementation(() => new Promise(resolve => { finish = resolve; }));
        await act(async () => root.render(<ProMarker state={{ ...base, entitled: true, installed: step === 'activate' }} />));
        const tag = container.querySelector('button')!;
        expect(tag.textContent).toBe('Pro');
        expect(tag.className).toContain(PRO_TAG_CLASS);
        expect(tag.getAttribute('aria-label')).toBe(step === 'install' ? 'Install Pro' : 'Activate Pro');
        await act(async () => tag.click());
        expect(tag.disabled).toBe(true);
        expect(tag.getAttribute('aria-busy')).toBe('true');
        await act(async () => tag.click());
        expect(action).toHaveBeenCalledTimes(1);
        await act(async () => finish({ ok: true }));
        expect(reloadPage).toHaveBeenCalledTimes(1);
    });

    it('renders the same static tag without a purchase or activation action when Pro is active', async () =>
    {
        await act(async () => root.render(<ProMarker state={{ ...base, entitled: true, installed: true, active: true }} />));
        expect(container.querySelector('button')).toBeNull();
        expect(container.querySelector('span')?.className).toContain(PRO_TAG_CLASS);
        expect(container.textContent).toBe('Pro');
    });
});
