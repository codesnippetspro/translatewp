import { expect, test } from '@playwright/test';

import { draggableTrigger, offline, seed, signIn, storedSwitcher, switcherTrigger } from '../support/site';

// Dragging the floating switcher in edit mode: the place it is let go of is
// where it stays, on a page with a real scrollbar, after a reload, and on a
// phone, where it keeps a position of its own.

test.describe('dragging the floating switcher', () =>
{
    test.use({ viewport: { width: 1280, height: 720 } });

    test.beforeEach(async ({ page }) =>
    {
        seed();
        await offline(page);
        await signIn(page);
    });

    test('lands exactly where it is released and stays there after a reload', async ({ page }) =>
    {
        await page.goto('/e2e-switcher/?twp-edit=1');
        const trigger = await draggableTrigger(page);
        expect(await page.evaluate(() => window.innerWidth - document.documentElement.clientWidth)).toBeGreaterThan(0);

        for (const [dx, dy] of [[-400, 250], [-650, -150]])
        {
            const before = (await trigger.boundingBox())!;
            const x = before.x + 20, y = before.y + before.height / 2;
            await page.mouse.move(x, y);
            await page.mouse.down();
            for (let step = 1; step <= 10; step++) await page.mouse.move(x + dx * step / 10, y + dy * step / 10);
            await page.mouse.up();
            await expect.poll(async () => { const box = (await trigger.boundingBox())!; return [Math.round(box.x), Math.round(box.y)]; })
                .toEqual([Math.round(before.x + dx), Math.round(before.y + dy)]);
        }

        const dropped = (await trigger.boundingBox())!;
        await expect.poll(() => storedSwitcher().floating_position).toBeTruthy();
        await page.reload();
        const after = (await (await switcherTrigger(page)).boundingBox())!;
        expect([Math.round(after.x), Math.round(after.y)]).toEqual([Math.round(dropped.x), Math.round(dropped.y)]);
    });

    test('a click without a drag still opens the language list', async ({ page }) =>
    {
        await page.goto('/e2e-switcher/?twp-edit=1');
        await (await switcherTrigger(page)).click();
        await expect(page.locator('.twp-dropdown')).toBeVisible();
        expect(storedSwitcher().floating_position).toBeUndefined();
    });

    test('the first-time hint, once closed, does not come back', async ({ page }) =>
    {
        await page.goto('/e2e-switcher/?twp-edit=1');
        await draggableTrigger(page);
        const hint = page.locator('.twp-toast', { hasText: 'Drag the switcher' });
        await expect(hint).toBeVisible();
        await hint.locator('.twp-toast-close').click();
        await page.reload();
        await draggableTrigger(page);
        await expect(page.locator('[data-twp-drag-dot]')).toBeVisible();
        await expect(hint).toHaveCount(0);
    });
});

test.describe('dragging on a phone', () =>
{
    test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

    test('a touch drag keeps a phone position of its own and leaves the desktop one alone', async ({ page, context }) =>
    {
        seed({ floating_position: { anchor: 'top-left', x: 300, y: 200 } });
        await offline(page);
        await signIn(page);
        await page.goto('/e2e-switcher/?twp-edit=1');
        const trigger = await draggableTrigger(page);
        const before = (await trigger.boundingBox())!;

        const cdp = await context.newCDPSession(page);
        const touch = (type: string, x: number, y: number) =>
            cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y }] });
        const x = before.x + before.width / 2, y = before.y + before.height / 2;
        await touch('touchStart', x, y);
        for (let step = 1; step <= 10; step++) await touch('touchMove', x - 100 * step / 10, y - 400 * step / 10);
        await touch('touchEnd', x - 100, y - 400);

        await expect.poll(async () => { const box = (await trigger.boundingBox())!; return [Math.round(box.x), Math.round(box.y)]; })
            // It stops at the edge of the screen.
            .toEqual([Math.max(0, Math.round(before.x - 100)), Math.round(before.y - 400)]);
        await expect.poll(() => storedSwitcher().floating_position_mobile).toBeTruthy();
        expect(storedSwitcher().floating_position).toEqual({ anchor: 'top-left', x: 300, y: 200 });

        await trigger.tap();
        await expect(page.locator('.twp-dropdown')).toBeVisible();
    });
});
