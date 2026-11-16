import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import { usePageHash } from '@/apps/admin/hooks/usePageHash';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let root: Root | null = null;

const Pages = () =>
{
    const { activePageName } = usePageHash(['languages', 'advanced'], 'languages');

    return (
        <div data-active={activePageName}>
            {'advanced' === activePageName && <div id="other-translation-plugins">Other translation plugins</div>}
        </div>
    );
};

const render = async (hash: string): Promise<HTMLElement> =>
{
    window.history.replaceState(null, '', `/wp-admin/admin.php?page=translatewp-settings${hash}`);
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root!.render(<Pages />);
    });
    return container;
};

describe('usePageHash', () =>
{
    const scrollIntoView = jest.fn();

    beforeEach(() =>
    {
        scrollIntoView.mockClear();
        Element.prototype.scrollIntoView = scrollIntoView;
    });

    afterEach(() =>
    {
        act(() => root?.unmount());
        document.body.innerHTML = '';
        window.history.replaceState(null, '', '/');
    });

    it('a hash with a section scrolls that section into view after the page opens', async () =>
    {
        const container = await render('#advanced/other-translation-plugins');

        expect(container.firstElementChild?.getAttribute('data-active')).toBe('advanced');
        expect(scrollIntoView).toHaveBeenCalledTimes(1);
        expect(scrollIntoView.mock.contexts[0]).toBe(document.getElementById('other-translation-plugins'));
    });

    it('a hash that only names a page opens it without scrolling', async () =>
    {
        const container = await render('#advanced');

        expect(container.firstElementChild?.getAttribute('data-active')).toBe('advanced');
        expect(scrollIntoView).not.toHaveBeenCalled();
    });
});
