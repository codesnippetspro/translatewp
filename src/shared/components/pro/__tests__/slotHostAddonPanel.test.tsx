import { act } from 'react';
import { createRoot } from 'react-dom/client';

import { ProSlotHost } from '@/shared/components/pro/ProSlotHost';
import type { ProPanelComponent } from '@/shared/host';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

// The addon's real style/tone panels take no props: they read and write the
// shared settings through the host bridge the free plugin installs on
// window.TranslateWP.host. These tests stub that bridge and mount the real
// ProSlotHost so the slot host path is exercised end to end.

type StyleShape = {
    type: string;
    tone: string;
    custom_style?: string;
    custom_tone?: string;
};

type BridgeStore = {
    settings: { 'translations.style': StyleShape };
    setSetting: (key: string, updater: unknown) => void;
};

type HostBridge = {
    version: number;
    useAdminSettingsStore: <T>(selector: (state: BridgeStore) => T) => T;
};

const installBridge = (style: StyleShape, version = 1): void =>
{
    const store: BridgeStore = {
        settings: { 'translations.style': style },
        setSetting: () => {},
    };

    (window as unknown as { TranslateWP: { host: HostBridge } }).TranslateWP = {
        host: {
            version,
            useAdminSettingsStore: (selector) => selector(store),
        },
    };
};

const bridge = (): HostBridge =>
    (window as unknown as { TranslateWP: { host: HostBridge } }).TranslateWP.host;

const renderHost = async (panel: ProPanelComponent): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);

    await act(async () =>
    {
        createRoot(container).render(<ProSlotHost panel={panel} />);
    });

    return container;
};

// Shaped like the addon's CustomStyleOptions after the fix: reads the style out
// of the host bridge, renders it, and takes no props.
const makeAddonShapedPanel = (): ProPanelComponent =>
{
    const Panel: ProPanelComponent = () =>
    {
        const style = bridge().useAdminSettingsStore((state) => state.settings['translations.style']);
        const custom = style.custom_style ?? '';

        return <div>style: {style.type} / custom {custom.length}</div>;
    };
    Panel.hostVersion = 1;

    return Panel;
};

// Shaped like the addon's broken pre-fix panel: it expects a `style` prop the
// single slot host never passes, so it throws while rendering.
const makePropsDependentPanel = (): ProPanelComponent =>
{
    const Panel = ((props: { style?: StyleShape }) => <div>{props.style!.type}</div>) as ProPanelComponent;
    Panel.hostVersion = 1;

    return Panel;
};

describe('ProSlotHost with an addon-shaped panel', () =>
{
    test('renders a self-contained panel that reads the stubbed host bridge', async () =>
    {
        installBridge({ type: 'neutral', tone: 'friendly', custom_style: 'brief' });

        const container = await renderHost(makeAddonShapedPanel());

        expect(container.textContent).toContain('style: neutral / custom 5');
        expect(container.textContent).not.toContain('This Pro panel could not load');
        expect(container.textContent).not.toContain('Update TranslateWP to use this Pro panel');

        container.remove();
    });

    test('a panel that needs props the slot host never passes trips the boundary and logs', async () =>
    {
        installBridge({ type: 'neutral', tone: 'friendly' });
        const errorSpy = jest.spyOn(console, 'error').mockImplementation(() => {});

        const container = await renderHost(makePropsDependentPanel());

        expect(container.textContent).toContain('This Pro panel could not load');

        const loggedPrefixedError = errorSpy.mock.calls.some(
            (args) => args[0] === '[TranslateWP] Pro panel failed'
        );
        expect(loggedPrefixedError).toBe(true);

        errorSpy.mockRestore();
        container.remove();
    });
});
