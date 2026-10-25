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

test('language headers remain semantic and the permissions table scrolls within a narrow page', async ({ page }) => {
	await page.goto('/wp-admin/admin.php?page=translatewp-settings#languages');
	const table = page.getByRole('table');
	for (const header of ['Language', 'Slug', 'Locale', 'Actions']) {
		await expect(table.getByRole('columnheader', { name: header, exact: true })).toBeVisible();
	}
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto('/wp-admin/admin.php?page=translatewp-settings#translation');
	const region = page.getByRole('region', { name: 'Who can manage translations', exact: true });
	await expect(region.getByRole('table')).toBeVisible();
	await expect(region).toHaveAttribute('tabindex', '0');
	const dimensions = await region.evaluate(element => ({
		viewport: window.innerWidth,
		page: document.documentElement.scrollWidth,
		visible: element.clientWidth,
		content: element.scrollWidth,
	}));
	expect(dimensions.page).toBeLessThanOrEqual(dimensions.viewport);
	expect(dimensions.content).toBeGreaterThan(dimensions.visible);
	await region.focus();
	await page.keyboard.press('ArrowRight');
	await expect.poll(() => region.evaluate(element => element.scrollLeft)).toBeGreaterThan(0);
});
