import { writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { expect, test, type Page } from '@playwright/test';
import webpack from 'webpack';

// The placed switcher from the source, built with React bundled in, on a bare page.
const out = resolve(__dirname, '../.fixtures/switcher-harness');
test.beforeAll(async () =>
{
    test.setTimeout(180_000);
    const config = require('../../webpack.config.js');
    const stats = await new Promise<webpack.Stats>((done, fail) => webpack({
        ...config,
        entry: { harness: resolve(__dirname, '../support/switcher-harness.tsx') },
        output: { path: out, filename: '[name].js' },
        devtool: false,
        plugins: config.plugins.filter((plugin: object) => ['DefinePlugin', 'MiniCssExtractPlugin'].includes(plugin.constructor.name)),
    }, (error, result) => (error || !result ? fail(error) : done(result))));
    if (stats.hasErrors()) throw new Error(stats.toString('errors-only'));
    writeFileSync(join(out, 'index.html'), '<!doctype html>');
});

type Place = { width: number; height: number; side: 'left' | 'right'; offset: number; trigger: 'flag' | 'name_flag'; dir: 'ltr' | 'rtl' };

const open = async (page: Page, { width, height, side, offset, trigger, dir }: Place) =>
{
    await page.setViewportSize({ width, height });
    await page.goto(pathToFileURL(join(out, 'index.html')).href);
    await page.setContent(`<!doctype html><html dir="${dir}"><body style="margin:0;font-family:sans-serif">
        <header style="position:relative;height:64px;border-bottom:1px solid #ddd"><span id="host" style="position:absolute;top:12px;${side}:${offset}px"></span></header>
        <main></main></body></html>`);
    await page.addStyleTag({ path: join(out, 'harness.css') });
    await page.addScriptTag({ path: join(out, 'harness.js') });
    await page.evaluate(trigger => (window as unknown as { mountSwitcher: (host: HTMLElement, trigger: string) => void }).mountSwitcher(document.getElementById('host')!, trigger), trigger);
    await page.locator('.twp-trigger').click();
    await expect(page.locator('.twp-promo')).toBeVisible();
};

const phones: Place[] = [];
for (const [width, height] of [[375, 667], [390, 844]])
    for (const side of ['right', 'left'] as const)
        for (const trigger of ['flag', 'name_flag'] as const)
            phones.push({ width, height, side, offset: 12, trigger, dir: 'ltr' });
phones.push({ width: 375, height: 667, side: 'left', offset: 12, trigger: 'flag', dir: 'rtl' });
phones.push({ width: 375, height: 667, side: 'right', offset: 12, trigger: 'flag', dir: 'rtl' });

for (const place of phones)
{
    test(`the open list stays on a ${place.width}px screen: ${place.dir} ${place.trigger} switcher at the ${place.side} end of a header`, async ({ page }) =>
    {
        await open(page, place);
        const screen = await page.evaluate(() => ({ width: document.documentElement.clientWidth, height: window.innerHeight, scroll: document.documentElement.scrollWidth }));
        expect(screen.scroll).toBe(screen.width);
        for (const target of ['.twp-dropdown', '.twp-dropdown-item >> nth=-1', '.twp-promo span'])
        {
            const rect = (await page.locator(target).boundingBox())!;
            expect(rect.x, target).toBeGreaterThanOrEqual(0);
            expect(rect.y, target).toBeGreaterThanOrEqual(0);
            expect(rect.x + rect.width, target).toBeLessThanOrEqual(screen.width);
            expect(rect.y + rect.height, target).toBeLessThanOrEqual(screen.height);
        }
        const names = await page.locator('.twp-dropdown-item').evaluateAll(items => items.every(item => item.scrollWidth <= item.clientWidth));
        expect(names).toBe(true);
    });
}

for (const trigger of ['flag', 'name_flag'] as const)
{
    test(`a desktop list that fits opens where it always did: ${trigger}`, async ({ page }) =>
    {
        await open(page, { width: 1350, height: 900, side: 'left', offset: 900, trigger, dir: 'ltr' });
        const button = (await page.locator('.twp-trigger').boundingBox())!;
        const list = (await page.locator('.twp-dropdown').boundingBox())!;
        expect(list.x).toBe(button.x);
        expect(list.y).toBe(button.y + button.height + 4);
    });
}
