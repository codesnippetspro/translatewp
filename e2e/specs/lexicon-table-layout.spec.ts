import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import { expect, test } from '@playwright/test';

const fixture = JSON.parse(readFileSync(resolve(__dirname, '../.fixtures/lexicon-table-markup.json'), 'utf8'));
const paths = ['src/apps/admin/components/Lexicon/LexiconTable/index.tsx', 'src/apps/admin/components/Lexicon/LexiconTable/style.css', 'src/shared/components/form/table/index.tsx'];
const sourceHash = paths.reduce((hash, path) => hash.update(readFileSync(resolve(__dirname, '../..', path))), createHash('sha256')).digest('hex');
if (fixture.sourceHash !== sourceHash) throw new Error('Refresh the LexiconTable browser fixture before running layout checks.');
const css = ['admin', 'style-admin'].map(name => readFileSync(resolve(__dirname, `../../build/${name}.css`), 'utf8')).join('\n');

for (const width of [390, 1350])
{
    test(`Lexicon terms and actions fit ${width}px without changing the desktop table`, async ({ page }) =>
    {
        await page.setViewportSize({ width, height: 1000 });
        await page.setContent(`<!doctype html><html><body style="margin:0"><main id="translatewp-admin" style="padding:16px"><section style="padding:16px;max-width:860px">${fixture.markup}</section></main></body></html>`);
        await page.addStyleTag({ content: css });
        expect(await page.evaluate(() => document.compatMode)).toBe('CSS1Compat');
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(width);
        const table = page.locator('.twp-lexicon-table');
        const bounds = (await table.boundingBox())!;
        for (const term of ['CODE-SNIPPETS', 'WooCommerce', 'Code Snippets'])
        {
            const cell = table.locator('tbody tr td:first-child').filter({ hasText: term }).first();
            const fragments = await cell.evaluate(element => {
                const text = element.firstChild!;
                return Array.from(text.textContent!.matchAll(/\S+/g), match => {
                    const range = document.createRange();
                    range.setStart(text, match.index!); range.setEnd(text, match.index! + match[0].length);
                    return range.getClientRects().length;
                });
            });
            expect(fragments.every(count => count === 1)).toBe(true);
            for (const action of ['Edit', 'Delete']) {
                const button = table.getByRole('button', { name: `${action} ${term}`, exact: true });
                await expect(button).toBeVisible();
                const rect = (await button.boundingBox())!;
                expect(rect.x).toBeGreaterThanOrEqual(bounds.x);
                expect(rect.x + rect.width).toBeLessThanOrEqual(bounds.x + bounds.width + 1);
            }
        }
        const first = table.locator('tbody tr').first();
        expect(await first.evaluate(row => getComputedStyle(row).display)).toBe(width === 390 ? 'grid' : 'table-row');
        if (width === 1350) {
            await expect(table.locator('thead th')).toHaveCount(4);
            const cells = await first.locator('td').evaluateAll(nodes => nodes.map(node => node.getBoundingClientRect()));
            expect(cells.every(rect => Math.abs(rect.y - cells[0].y) < 1)).toBe(true);
        }
    });

    test(`off-screen shared table headers stay inside the scroll box at ${width}px`, async ({ page }) =>
    {
        await page.setViewportSize({ width, height: 800 });
        await page.setContent(`<!doctype html><html><body style="margin:0"><main id="translatewp-admin" style="padding:32px;max-width:860px">${fixture.scrollMarkup}</main></body></html>`);
        await page.addStyleTag({ content: css });
        expect(await page.evaluate(() => document.compatMode)).toBe('CSS1Compat');
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(width);
        if (width === 390) {
            const scroll = page.locator('table').locator('..');
            expect(await scroll.evaluate(node => node.scrollWidth)).toBeGreaterThan(await scroll.evaluate(node => node.clientWidth));
        }
    });
}
