import { expect, test } from '@playwright/test';

import { offline, seed, signIn, storedSwitcher } from '../support/site';

test.beforeEach(async ({ page }) => {
	seed({ regional_names: 'full' });
	await offline(page);
	await signIn(page);
});

test('switcher controls have names and radios support keyboard selection with visible focus', async ({ page }) => {
	await page.goto('/wp-admin/admin.php?page=translatewp-settings#switcher');
	for (const label of [
		'Text color', 'Background color', 'Highlight text color',
		'Highlight background', 'Border color', 'Loading border color',
	]) {
		await expect(page.getByRole('textbox', { name: label, exact: true })).toHaveCount(1);
	}
	for (const label of [
		'Flag icon style', 'Background', 'Switcher type', 'Show selected language', 'Language names',
	]) {
		await expect(page.getByRole('radiogroup', { name: label, exact: true })).toHaveCount(1);
	}
	const lockedSideTab = page.getByRole('radio', { name: 'Side tab', exact: true });
	await expect(lockedSideTab).toBeDisabled();
	await expect(lockedSideTab).toHaveCSS('opacity', '0');
	const group = page.getByRole('radiogroup', { name: 'Language names', exact: true });
	const full = group.getByRole('radio', { name: 'Full name', exact: true });
	const short = group.getByRole('radio', { name: 'Short', exact: true });
	await expect(full).toBeChecked();
	await full.focus();
	await page.keyboard.press('ArrowRight');
	await expect(short).toBeChecked();
	await expect(short).toBeFocused();
	await expect(short.locator('..')).toHaveCSS('outline-style', 'solid');
	expect(await short.locator('..').evaluate(element => parseFloat(getComputedStyle(element).outlineWidth)))
		.toBeGreaterThan(0);
	await expect.poll(() => storedSwitcher().regional_names).toBe('short');
	await page.keyboard.press('ArrowLeft');
	await expect(full).toBeChecked();
	await expect.poll(() => storedSwitcher().regional_names).toBe('full');
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

test('language-table action buttons do not compress or clip their icons on mobile', async ({ page }) => {
	await page.setViewportSize({ width: 320, height: 800 });
	await page.goto('/wp-admin/admin.php?page=translatewp-settings#languages');
	const table = page.getByRole('table');
	await expect(table).toBeVisible();
	const buttons = await table.getByRole('button').all();
	expect(buttons.length).toBeGreaterThan(0);
	for (const button of buttons) {
		const dimensions = await button.evaluate(element => ({
			visible: element.clientWidth,
			content: element.scrollWidth,
		}));
		expect(dimensions.content).toBeLessThanOrEqual(dimensions.visible + 1);
	}
	const localeLines = await table.getByRole('columnheader', { name: 'Locale', exact: true })
		.evaluate(element => {
			const range = document.createRange();
			range.selectNodeContents(element);
			return range.getClientRects().length;
		});
	expect(localeLines).toBe(1);
	const pageWidth = await page.evaluate(() => ({
		viewport: window.innerWidth,
		content: document.documentElement.scrollWidth,
	}));
	expect(pageWidth.content).toBeLessThanOrEqual(pageWidth.viewport);
});

test('an unbroken lexicon term wraps without widening the term column across the screen', async ({ page }) => {
	const term = 'A'.repeat(200);
	await page.route('**/translatewp/v1/admin/get-lexicon-terms?*', route => route.fulfill({
		json: {
			items: [{ id: 1, term, description: '', languages: ['en-us'], categories: [] }],
			pagination: { page: 1, per_page: 10, total: 1, total_pages: 1, has_next: false, has_prev: false },
		},
	}));
	await page.setViewportSize({ width: 375, height: 812 });
	await page.goto('/wp-admin/admin.php?page=translatewp-lexicon');
	const cell = page.getByRole('cell', { name: term, exact: true });
	await expect(cell).toBeVisible();
	await expect(cell).toHaveCSS('overflow-wrap', 'anywhere');
	const dimensions = await cell.evaluate(element => ({
		width: element.getBoundingClientRect().width,
		viewport: window.innerWidth,
	}));
	expect(dimensions.width).toBeLessThanOrEqual(dimensions.viewport / 2);
	await expect(page.getByRole('button', { name: 'Edit lexicon term', exact: true })).toBeVisible();
});
