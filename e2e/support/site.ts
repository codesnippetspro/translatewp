import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { expect, type Page } from '@playwright/test';

const ROOT = path.resolve(__dirname, '..', '..');

/** Runs WP-CLI on the wp-env tests site and returns its output. */
export const wp = (...args: string[]): string =>
    execFileSync('npx', ['wp-env', 'run', 'tests-cli', 'wp', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });

/** Sets up the site, with the switcher settings given on top of the defaults. */
let addressesReady = false;

export const seed = (switcher: Record<string, unknown> = {}): void =>
{
    // The language folders need pretty addresses; a fresh wp-env site has plain ones.
    if (!addressesReady)
    {
        wp('rewrite', 'structure', '/%postname%/', '--hard');
        addressesReady = true;
    }
    const output = wp('eval-file', 'wp-content/translatewp-e2e/seed.php', JSON.stringify(switcher));
    if (!output.includes('E2E_SEEDED')) throw new Error(`Seeding the e2e site failed:\n${output}`);
};

/** The stored switcher settings. */
export const storedSwitcher = (): Record<string, unknown> =>
    JSON.parse(wp('eval', 'echo wp_json_encode( \\TranslateWP\\Components\\Options::get( "languages.switcher", [] ) );').trim().split('\n').pop() ?? '{}');

export const signIn = async (page: Page): Promise<void> =>
{
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
};

/** The floating switcher's button, once the switcher is on the page. */
export const switcherTrigger = async (page: Page) =>
{
    const trigger = page.locator('.twp-container .twp-trigger');
    await expect(trigger).toBeVisible();
    return trigger;
};

/** The switcher's button once edit mode's drag code has loaded, so a drag can start. */
export const draggableTrigger = async (page: Page) =>
{
    const trigger = page.locator('.twp-container .twp-trigger[data-drag-handle="true"]');
    await expect(trigger).toBeVisible();
    return trigger;
};

/** Nothing on these pages may reach the translation service. */
export const offline = async (page: Page): Promise<void> =>
{
    await page.route('http://translatewp-e2e.invalid/**', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{}' }));
};
