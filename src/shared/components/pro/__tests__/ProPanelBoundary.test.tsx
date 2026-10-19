import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ProPanelBoundary } from '@/shared/components/pro/ProPanelBoundary';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const ThrowingPanel = () =>
{
    throw new Error('panel exploded');
};

describe('ProPanelBoundary', () =>
{
    test('renders the fixing message and logs a prefixed error when a panel throws', async () =>
    {
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});
        const container = document.createElement('div');
        document.body.appendChild(container);

        await act(async () =>
        {
            createRoot(container).render(
                <ProPanelBoundary>
                    <ThrowingPanel />
                </ProPanelBoundary>
            );
        });

        expect(container.textContent).toContain(
            'This Pro panel could not load. Reload the page. If it keeps failing, update TranslateWP and TranslateWP Pro from your Plugins screen.'
        );
        expect(container.querySelector('a')?.getAttribute('href')).toBe('plugins.php');

        const loggedPrefixedError = errorSpy.mock.calls.some(
            (args) => args[0] === '[TranslateWP] Pro panel failed'
        );
        expect(loggedPrefixedError).toBe(true);

        errorSpy.mockRestore();
        container.remove();
    });

    test('renders its children unchanged when the panel does not throw', async () =>
    {
        const container = document.createElement('div');
        document.body.appendChild(container);

        await act(async () =>
        {
            createRoot(container).render(
                <ProPanelBoundary>
                    <div>healthy panel</div>
                </ProPanelBoundary>
            );
        });

        expect(container.textContent).toContain('healthy panel');
        expect(container.textContent).not.toContain('This Pro panel could not load');

        container.remove();
    });
});
