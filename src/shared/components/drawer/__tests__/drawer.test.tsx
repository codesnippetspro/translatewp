import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { Drawer } from '@/shared/components/drawer';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mountedRoots: Root[] = [];

const renderDrawer = async (isOpen: boolean, onClose: () => void): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const root = createRoot(container);
    mountedRoots.push(root);
    await act(async () =>
    {
        root.render(
            <Drawer isOpen={isOpen} onClose={onClose} title="Edit Language">
                <span>Drawer body</span>
            </Drawer>
        );
    });
    return container;
};

describe('Drawer visibility is driven by isOpen', () =>
{
    afterEach(async () =>
    {
        await act(async () =>
        {
            for (const root of mountedRoots.splice(0)) {
                root.unmount();
            }
        });
        document.body.innerHTML = '';
    });

    it('renders nothing when closed', async () =>
    {
        const container = await renderDrawer(false, () => {});

        expect(container.querySelector('[role="dialog"]')).toBeNull();
        expect(container.textContent).not.toContain('Edit Language');
        expect(container.textContent).not.toContain('Drawer body');
    });

    it('renders the dialog and its body when open', async () =>
    {
        const container = await renderDrawer(true, () => {});

        expect(container.querySelector('[role="dialog"]')).not.toBeNull();
        expect(container.textContent).toContain('Drawer body');
    });

    it('asks to close on Escape', async () =>
    {
        let closed = 0;
        await renderDrawer(true, () => { closed += 1; });

        await act(async () =>
        {
            window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        });

        expect(closed).toBe(1);
    });
});
