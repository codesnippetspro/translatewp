import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';

const fixture = JSON.parse(readFileSync(resolve(__dirname, '../.fixtures/manager-style-markup.json'), 'utf8'));
const hostile = `body{font-family:fantasy;font-size:28px;line-height:3;color:purple}
.kit button{background:orange;border-radius:30px;font-family:fantasy;text-transform:uppercase;padding:24px;border:7px solid red;letter-spacing:5px}
a{color:red;text-decoration:underline}input{border:5px solid lime}h2{font-size:60px}ul li{list-style:square;margin-left:40px}`;
const properties = ['fontFamily', 'fontSize', 'fontWeight', 'lineHeight', 'color', 'backgroundColor', 'borderTopWidth', 'borderTopColor', 'borderRadius', 'padding', 'textTransform', 'letterSpacing', 'textDecorationLine'];
for (const width of [1350, 390]) {
    test(`manager controls resist site rules without resetting visitor fonts at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<!doctype html><html><body class="kit" style="margin:0"><button id="site-button">Site</button><div id="manager">${fixture.manager}</div><div id="visitor">${fixture.visitor}</div></body></html>`);
        const measure = () => page.evaluate(properties => Object.fromEntries(['.twp-admin-item', '.twp-toast', '.twp-toast-action', '.twp-toast-close'].map(selector => [selector,
            Array.from(document.querySelectorAll('#manager ' + selector)).map(node => {
                const style = getComputedStyle(node);
                return Object.fromEntries(properties.map(property => [property, style[property as keyof CSSStyleDeclaration]]));
            }),
        ])), properties);
        const clean = await measure();
        await page.addStyleTag({ content: hostile });
        expect(await measure()).toEqual(clean);
        await expect(page.locator('#site-button')).toHaveCSS('background-color', 'rgb(255, 165, 0)');
        await expect(page.locator('#visitor .twp-dropdown-item').first()).toHaveCSS('font-family', 'fantasy');
        await page.addStyleTag({ content: '#visitor .twp-trigger{outline:3px solid rgb(123,45,67)!important}' });
        await expect(page.locator('#visitor .twp-trigger')).toHaveCSS('outline-color', 'rgb(123, 45, 67)');
    });
}

for (const width of [1350, 390]) {
    test(`every visitor computed style is identical with manager CSS present at ${width}px`, async ({ page }) => {
        const { managerStyles } = fixture;
        expect(fixture.visitor).toContain(managerStyles);
        await page.setViewportSize({ width, height: 900 });
        await page.setContent(`<!doctype html><html><body class="kit"><div id="visitor">${fixture.visitor.replaceAll(managerStyles, '')}</div></body></html>`);
        await page.addStyleTag({ content: hostile + '#visitor .twp-trigger{outline:3px solid rgb(123,45,67)!important}' });
        await page.evaluate(async () => {
            const animations = document.getAnimations();
            for (const animation of animations) { animation.pause(); animation.currentTime = 0; }
            await Promise.all(animations.map(animation => animation.ready));
        });
        const styles = () => page.locator('#visitor, #visitor *').evaluateAll(nodes => nodes.map(node => {
            const style = getComputedStyle(node);
            const rect = node.getBoundingClientRect();
            return { tag: node.tagName, styles: Object.fromEntries(Array.from(style).map(property => [property, style.getPropertyValue(property)])),
                rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height }, scrollWidth: node.scrollWidth, scrollHeight: node.scrollHeight };
        }));
        const without = await styles();
        expect(without.length).toBeGreaterThan(10);
        await page.addStyleTag({ content: managerStyles });
        expect(await styles()).toEqual(without);
    });
}
