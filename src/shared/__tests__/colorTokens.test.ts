import { readFileSync } from 'fs';
import { join } from 'path';

// The values come from the portal: translatewp-app src/app/globals.css (@theme).
const PORTAL = {
    primary: '#0e3ecd',
    admin: '#2271B1',
    neutral: '#343739',
    muted: '#646970',
    border: '#E2E2E4',
    icons: '#9CA1A8',
    selection: '#F6F7F7',
    grip: '#8D8F95',
    cta: '#2E7D57',
    'cta-hover': '#276B4A',
};

const ACCESSIBLE_STATUS = { success: '#2E7D57', warning: '#946200', error: '#B32D2E' };

// The portal's neutral surface scale (translatewp-app src/app/globals.css
// @theme): the tones the ported chip and button surfaces sit on. The chrome
// blue stays the product brand accent (translatewp-primary), not the portal's
// second blue, so only the non-blue neutrals are mirrored here. White is
// written short per the lint rule; the portal value #ffffff is the same.
const PORTAL_TWP = {
    'neutral-0': '#fff',
    'neutral-100': '#f0f0ee',
    'neutral-200': '#e4e4e4',
    'neutral-500': '#6e6e6e',
    'neutral-800': '#303030',
};

const theme = readFileSync(join(__dirname, '..', 'tailwind.css'), 'utf8');
const switcher = readFileSync(join(__dirname, '..', '..', 'apps', 'translatewp', 'SwitcherView.tsx'), 'utf8');

describe('the plugin colour tokens', () =>
{
    it.each(Object.entries(PORTAL))('%s equals the portal value', (role, value) =>
    {
        expect(theme).toMatch(new RegExp(`--color-translatewp-${role}: ${value};`));
    });

    it.each(Object.entries(ACCESSIBLE_STATUS))('%s uses the accepted accessible status color', (role, value) =>
    {
        expect(theme).toContain(`--color-translatewp-${role}: ${value};`);
        const channels = value.slice(1).match(/../g)!.map(channel => parseInt(channel, 16) / 255);
        const linear = channels.map(channel => channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4);
        const luminance = linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
        expect(1.05 / (luminance + 0.05)).toBeGreaterThanOrEqual(4.5);
    });

    it.each(Object.entries(PORTAL_TWP))('twp %s equals the portal value', (role, value) =>
    {
        expect(theme).toMatch(new RegExp(`--color-twp-${role}: ${value};`));
    });

    it('the visitor switcher border is the border token value', () =>
    {
        expect(switcher).toContain(`1px solid ${PORTAL.border}`);
        expect(switcher).not.toMatch(/1px solid #(?!E2E2E4)[0-9A-Fa-f]{6}/);
    });
});
