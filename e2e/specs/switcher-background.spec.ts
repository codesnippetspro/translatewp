import { expect, test } from '@playwright/test';

import { offline, seed, signIn, storedSwitcher, switcherTrigger } from '../support/site';

// The background choice made in Settings shows on the site: solid paints the
// colour, transparent shows the page through an outline, matte glass frosts.

const look = (page: import('@playwright/test').Page) => page.locator('.twp-container .twp-trigger').evaluate(element =>
{
    const style = getComputedStyle(element);
    return { background: style.backgroundColor, blur: style.backdropFilter || (style as unknown as Record<string, string>).webkitBackdropFilter || '', color: style.color };
});

test.describe('the switcher background', () =>
{
    test.beforeEach(async ({ page }) =>
    {
        seed();
        await offline(page);
        await signIn(page);
    });

    for (const [choice, stored] of [['Matte glass', 'glass'], ['Transparent', 'transparent'], ['Solid', 'solid']] as const)
    {
        test(`${choice}, chosen in Settings, is what the site shows`, async ({ page }) =>
        {
            if (stored === 'solid') seed({ background_style: 'glass' });
            await page.goto('/wp-admin/admin.php?page=translatewp-settings#switcher');
            await page.getByRole('radiogroup', { name: 'Background', exact: true }).getByRole('radio', { name: choice, exact: true }).click();
            await expect(page.getByRole('status').filter({ hasText: 'Saved' })).toBeVisible();
            expect(storedSwitcher().background_style).toBe(stored);
            if (stored !== 'solid') await expect(page.locator('[data-twp-switcher-preview]')).toHaveAttribute('data-busy-background', 'true');

            await page.goto('/e2e-switcher/');
            await switcherTrigger(page);
            const seen = await look(page);
            if (stored === 'glass')
            {
                expect(seen.blur).toContain('blur(14px)');
                expect(seen.background).toBe('rgba(255, 255, 255, 0.6)');
            }
            else if (stored === 'transparent')
            {
                expect(seen.background).toBe('rgba(0, 0, 0, 0)');
                expect(seen.color).toBe('rgb(255, 255, 255)');
            }
            else
            {
                expect(seen.background).toBe('rgb(255, 255, 255)');
                expect(seen.blur).toBe('none');
            }
        });
    }
});
