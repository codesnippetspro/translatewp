import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { useUnsavedChangesGuard } from '@/apps/admin/hooks/useUnsavedChangesGuard';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const Probe = ({ hasChanges }: { hasChanges: boolean }) =>
{
    useUnsavedChangesGuard(hasChanges);
    return null;
};

const fire = () =>
{
    const event = new Event('beforeunload', { cancelable: true }) as BeforeUnloadEvent;
    window.dispatchEvent(event);
    return event.defaultPrevented;
};

describe('useUnsavedChangesGuard', () =>
{
    afterEach(() => { document.body.innerHTML = ''; });

    it('asks before leaving only while there are unsaved changes', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);
        const root = createRoot(container);

        await act(async () => { root.render(<Probe hasChanges={false} />); });
        expect(fire()).toBe(false);

        await act(async () => { root.render(<Probe hasChanges={true} />); });
        expect(fire()).toBe(true);

        await act(async () => { root.render(<Probe hasChanges={false} />); });
        expect(fire()).toBe(false);
    });
});
