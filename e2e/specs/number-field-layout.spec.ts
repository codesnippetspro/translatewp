import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { createHash } from 'node:crypto';
import { expect, test } from '@playwright/test';

const fixture = JSON.parse(readFileSync(resolve(__dirname, '../.fixtures/number-field-markup.json'), 'utf8'));
const sourceHash = createHash('sha256').update(readFileSync(resolve(__dirname, '../../src/shared/components/form/numberField/index.tsx')))
    .update(readFileSync(resolve(__dirname, '../../src/shared/components/form/numberField/values.ts'))).digest('hex');
if (fixture.sourceHash !== sourceHash) throw new Error('Run the numberField Jest suite to refresh the browser markup fixture.');
const markup: string = fixture.markup;
const styles = (direction: 'ltr' | 'rtl') => ['admin', 'style-admin'].map(name =>
    readFileSync(resolve(__dirname, `../../build/${name}${direction === 'rtl' ? '-rtl' : ''}.css`), 'utf8')).join('\n');

for (const width of [390, 1350])
{
    for (const direction of ['ltr', 'rtl'] as const)
    {
        test(`number fields keep logical action order and fit ${width}px in ${direction}`, async ({ page }) =>
        {
            await page.setViewportSize({ width, height: 800 });
            await page.setContent(`<!doctype html><html dir="${direction}"><head></head><body><main id="translatewp-admin" style="padding:16px">${markup}</main></body></html>`);
            await page.addStyleTag({ content: styles(direction) });
            for (const label of ['Font size', 'Keep the choice for (days)'])
            {
                const decrease = page.getByRole('button', { name: `Decrease ${label}`, exact: true });
                const increase = page.getByRole('button', { name: `Increase ${label}`, exact: true });
                const input = page.getByRole('spinbutton', { name: label, exact: true });
                await expect(input).toBeVisible();
                const left = (await decrease.boundingBox())!;
                const value = (await input.boundingBox())!;
                const right = (await increase.boundingBox())!;
                if (direction === 'ltr') {
                    expect(left.x + left.width).toBeLessThanOrEqual(value.x);
                    expect(value.x + value.width).toBeLessThanOrEqual(right.x);
                } else {
                    expect(right.x + right.width).toBeLessThanOrEqual(value.x);
                    expect(value.x + value.width).toBeLessThanOrEqual(left.x);
                }
                const control = (await decrease.locator('..').boundingBox())!;
                expect(control.x).toBeGreaterThanOrEqual(0);
                expect(control.x + control.width).toBeLessThanOrEqual(width);
                expect(left.y).toBe(right.y);
                expect(left.y).toBe(value.y);
            }
        });
    }
}
