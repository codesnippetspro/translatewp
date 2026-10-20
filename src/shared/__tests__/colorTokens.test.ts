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
    success: '#45A366',
    warning: '#c98a1e',
    cta: '#2E7D57',
    'cta-hover': '#276B4A',
    // stylelint wants the short form; #F00 is #FF0000.
    error: '#F00',
};

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
