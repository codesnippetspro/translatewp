import { expect, test } from '@playwright/test';

import { offline, seed, signIn, storedSwitcher } from '../support/site';

// Settings that do nothing for the chosen switcher are disabled with a short
// reason, never silently ignored.

test('the native dropdown disables what it does not use, and says why', async ({ page }) =>
{
    seed();
    await offline(page);
    await signIn(page);
    await page.goto('/wp-admin/admin.php?page=translatewp-settings#switcher');

    await page.locator('[data-option-cards="switcher-type"] [role="radio"]', { hasText: 'Native dropdown' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Saved' })).toBeVisible();
    expect(storedSwitcher().type).toBe('native_select');

    const reasons = page.locator('[data-unavailable-reason]');
    await expect(reasons.filter({ hasText: 'always shows the language name' })).toBeVisible();
    await expect(reasons.filter({ hasText: 'emoji flags' })).toBeVisible();
    await expect(reasons.filter({ hasText: 'chooses its own highlight' })).toBeVisible();
    await expect(reasons.filter({ hasText: 'only the offset applies' })).toBeVisible();

    // A disabled choice cannot be picked.
    const before = storedSwitcher().trigger;
    await page.locator('[data-option-cards="show-selected-language"] [role="radio"]', { hasText: 'Icon only' }).click({ force: true });
    await page.waitForTimeout(500);
    expect(storedSwitcher().trigger).toBe(before);

    await expect(page.getByText('Native select')).toHaveCount(0);
});
