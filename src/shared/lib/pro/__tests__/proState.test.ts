import { getProState, isProActive, proNextStep, shouldShowUpsell, type ProState } from '@/shared/lib/pro/proState';

const setProState = (value: unknown) =>
{
    Object.defineProperty(window, 'translatewpPro', { value, writable: true, configurable: true });
};

const base: ProState = {
    connected: true,
    entitled: false,
    installed: false,
    active: false,
    version: '',
    upgradeUrl: 'https://app.translatewp.ai/upgrade',
    upgradeStage: null,
};

describe('pro state', () =>
{
    afterEach(() => setProState(undefined));

    it('falls back to safe defaults when the global is missing', () =>
    {
        setProState(undefined);
        const state = getProState();

        expect(state.connected).toBe(false);
        expect(state.entitled).toBe(false);
        expect(state.installed).toBe(false);
        expect(state.active).toBe(false);
        expect(state.version).toBe('');
        expect(state.upgradeUrl).toBe('https://app.translatewp.ai/upgrade');
    });

    it('reads the localized entitlement state', () =>
    {
        setProState({
            connected: true,
            entitled: true,
            installed: true,
            active: true,
            version: '1.2.0',
            upgradeUrl: 'https://app.translatewp.ai/upgrade?return=admin',
        });
        const state = getProState();

        expect(state.entitled).toBe(true);
        expect(state.installed).toBe(true);
        expect(state.version).toBe('1.2.0');
        expect(state.upgradeUrl).toBe('https://app.translatewp.ai/upgrade?return=admin');
    });

    it('shows the upsell only when the account is not entitled', () =>
    {
        expect(shouldShowUpsell({ ...base, entitled: false })).toBe(true);
        expect(shouldShowUpsell({ ...base, entitled: true })).toBe(false);
    });

    it('reports pro active only when entitled, installed and active', () =>
    {
        expect(isProActive({ ...base, entitled: true, installed: true, active: true })).toBe(true);
        expect(isProActive({ ...base, entitled: true, installed: true, active: false })).toBe(false);
        expect(isProActive({ ...base, entitled: true, installed: false })).toBe(false);
        expect(isProActive({ ...base, entitled: false, installed: true, active: true })).toBe(false);
    });

    it('resolves the next Pro step', () =>
    {
        expect(proNextStep({ ...base, entitled: true, installed: false })).toBe('install');
        expect(proNextStep({ ...base, entitled: true, installed: true, active: false })).toBe('activate');
        expect(proNextStep({ ...base, entitled: false })).toBe('upgrade');
        expect(proNextStep({ ...base, entitled: false, upgradeStage: 'checkout_started' })).toBeNull();
        expect(proNextStep({ ...base, entitled: true, installed: true, active: true })).toBeNull();
    });
});
