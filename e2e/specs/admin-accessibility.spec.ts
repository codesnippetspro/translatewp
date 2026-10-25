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
