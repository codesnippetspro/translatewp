import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

const order: string[] = [];

jest.mock('@/shared/api/connect', () => ({
    getConnectStatus: () => Promise.resolve({ connected: true, pro: { entitled: true, installed: false, active: false } }),
    installPro: () => Promise.resolve({ ok: true }),
    activatePro: () => Promise.resolve({ ok: true }),
}));
jest.mock('@/shared/lib/reloadPage', () => ({ reloadPage: () => { order.push('reload'); } }));

import { ProLock } from '@/shared/components/pro/ProLock';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

afterEach(async () =>
{
    await act(async () => { root?.unmount(); });
    root = null;
    document.body.innerHTML = '';
});

describe('a Pro lock with a marker', () =>
{
    it('lets a button in the marker take its own clicks above the lock', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
        const onMarker = jest.fn();
        await act(async () =>
        {
            root!.render(
                <ProLock feature="alt text" badge={<button type="button" onClick={onMarker}>Activate Pro</button>}>
                    <span>Translate image alt tags</span>
                </ProLock>
            );
        });

        const badge = container.querySelector('[data-pro-lock-badge]') as HTMLElement;
        expect(badge.className).toContain('pointer-events-none');
        expect(badge.className).toContain('[&_button]:pointer-events-auto');
        expect(badge.className).toContain('z-10');

        await act(async () => { (badge.querySelector('button') as HTMLButtonElement).click(); });
        expect(onMarker).toHaveBeenCalledTimes(1);
        expect(document.body.textContent).not.toContain('Maybe later');
    });

    it('reports Pro ready before the reload when the install starts from its prompt', async () =>
    {
        order.length = 0;
        const container = document.createElement('div');
        document.body.appendChild(container);
        root = createRoot(container);
        await act(async () =>
        {
            root!.render(<ProLock feature="Legal" onProReady={() => order.push('ready')}><span>Legal</span></ProLock>);
        });
        await act(async () => { (container.querySelector('button[aria-label^="Unlock"]') as HTMLButtonElement).click(); });
        await act(async () => { await Promise.resolve(); });
        const install = [...document.body.querySelectorAll('button')].find(button => button.textContent?.trim() === 'Install now') as HTMLButtonElement;
        await act(async () => { install.click(); });
        await act(async () => { await Promise.resolve(); });
        expect(order).toEqual(['ready', 'reload']);
    });
});
