import { expect, test } from '@playwright/test';

import { offline, seed, signIn, storedSwitcher } from '../support/site';

test.beforeEach(async ({ page }) => {
	seed({ regional_names: 'full' });
	await offline(page);
	await signIn(page);
});

test('color inputs have accessible names', async ({ page }) => {
	await page.goto('/wp-admin/admin.php?page=translatewp-settings#switcher');
	for (const label of [
		'Text color', 'Background color', 'Highlight text color',
		'Highlight background', 'Border color', 'Loading border color',
	]) {
		await expect(page.getByRole('textbox', { name: label, exact: true })).toHaveCount(1);
	}
});

test('search focus remains visible in wp-admin and feedback has an accessible name', async ({ page }) => {
	await page.goto('/wp-admin/admin.php?page=translatewp#indexing');
	const search = page.getByRole('textbox', { name: 'Add a page or post', exact: true });
	await search.focus();
	await expect(search).toBeFocused();
	await expect(search.locator('..')).toHaveCSS('outline-style', 'solid');
	expect(await search.locator('..').evaluate(element => parseFloat(getComputedStyle(element).outlineWidth)))
		.toBeGreaterThan(0);
	await page.goto('/wp-admin/admin.php?page=translatewp#support');
	await expect(page.getByRole('textbox', {
		name: 'Tell us what would make TranslateWP better', exact: true,
	})).toBeVisible();
});
