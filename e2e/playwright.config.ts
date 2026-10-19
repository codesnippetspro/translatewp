import { defineConfig } from '@playwright/test';

// The end-to-end checks run against the wp-env tests site (`yarn e2e:env`),
// one worker in order: they share one site and change its settings.
export default defineConfig({
    testDir: './specs',
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    use: {
        baseURL: process.env.TRANSLATEWP_E2E_URL ?? 'http://localhost:8889',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        // Chromium hides scrollbars when headless; visitors' browsers show them,
        // and a dragged switcher must land exactly where it is let go with one showing.
        launchOptions: { ignoreDefaultArgs: ['--hide-scrollbars'] },
    },
    outputDir: './results',
});
