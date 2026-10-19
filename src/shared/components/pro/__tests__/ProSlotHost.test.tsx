import { TextEncoder } from 'node:util';
import { renderToStaticMarkup } from 'react-dom/server.node';

import { ProSlotHost } from '@/shared/components/pro/ProSlotHost';
import type { ProPanelComponent } from '@/shared/host';

// polyfill: jsdom environment lacks TextEncoder which react-dom/server needs
if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

type WindowWithHost = { TranslateWP: { host?: { version: number } } };

const setHostVersion = (version: number | undefined) =>
{
    (window as unknown as WindowWithHost).TranslateWP =
        version === undefined ? ({} as WindowWithHost['TranslateWP']) : { host: { version } };
};

const makePanel = (hostVersion?: number): ProPanelComponent =>
{
    const Panel: ProPanelComponent = () => <div>panel body</div>;
    Panel.hostVersion = hostVersion;
    return Panel;
};

const UPDATE_MESSAGE = 'Update TranslateWP to use this Pro panel.';

describe('ProSlotHost version gate', () =>
{
    test('shows the update message when the panel needs a newer host', () =>
    {
        setHostVersion(1);
        const html = renderToStaticMarkup(<ProSlotHost panel={makePanel(2)} />);

        expect(html).toContain(UPDATE_MESSAGE);
        expect(html).not.toContain('panel body');
    });

    test('mounts the panel when the host satisfies the declared version', () =>
    {
        setHostVersion(1);
        const html = renderToStaticMarkup(<ProSlotHost panel={makePanel(1)} />);

        expect(html).toContain('panel body');
        expect(html).not.toContain(UPDATE_MESSAGE);
    });

    test('mounts a panel that declares no version requirement', () =>
    {
        setHostVersion(1);
        const html = renderToStaticMarkup(<ProSlotHost panel={makePanel()} />);

        expect(html).toContain('panel body');
        expect(html).not.toContain(UPDATE_MESSAGE);
    });
});
