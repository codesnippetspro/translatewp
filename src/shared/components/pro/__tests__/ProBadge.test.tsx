import { act } from 'react';
import { createRoot } from 'react-dom/client';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

import { ProBadge } from '@/shared/components/pro/ProBadge';

describe('ProBadge', () =>
{
    it('is the portal plan-name badge and never wraps its label', () =>
    {
        const container = document.createElement('div');
        const root = createRoot(container);
        act(() => root.render(<ProBadge label="Orbital lifetime" />));
        const badge = container.querySelector('span') as HTMLElement;

        expect(badge.className).toContain('rounded-md');
        expect(badge.className).toContain('bg-translatewp-primary/10');
        expect(badge.className).toContain('whitespace-nowrap');
        expect(badge.className).toContain('shrink-0');
        expect(badge.textContent).toBe('Orbital lifetime');
        act(() => root.unmount());
    });
});
