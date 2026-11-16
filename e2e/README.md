# End-to-end checks

Playwright checks of the switcher against a real WordPress, run on your machine.
They cover the paths a site owner relies on: dragging the floating switcher and
where it is saved (desktop with a visible scrollbar, and touch on a phone), the
background choice from Settings to the site, and the switcher settings that are
disabled with a reason.

## Run

```bash
yarn build                      # the checks use the built plugin
yarn e2e:env                    # starts wp-env (Docker); the tests site is http://localhost:8889
npx playwright install chromium # once
yarn e2e
```

Each check seeds the wp-env tests site (`e2e/support/seed.php`) into a set-up,
connected state with two languages. Nothing leaves the machine: the plugin is
pointed at an address that does not exist and `e2e/support/mu-plugin.php`
answers its calls, and the browser's calls to the service are answered by the
checks themselves.

`wp-env` is pinned to 10.38, the last version that runs on Node 22.

## Notes

- Chromium hides scrollbars when headless; the config shows them, since a
  dragged switcher must land exactly where it is let go on a page with one.
- Switching branches while `wp-env` runs can leave its mounted folders empty.
  Run `npx wp-env stop && yarn e2e:env` after switching.

## Number-field layout without WordPress

The isolated number-field check uses markup rendered from the production component
by its Jest suite and the built admin CSS. It checks actual left-to-right and
right-to-left positions at 390px and desktop widths, without a running WordPress.
A source hash rejects a stale markup fixture. `yarn e2e` refreshes it automatically
through the `pree2e` hook; direct Playwright calls use the two-step command below.

```bash
yarn build
CI=true yarn test --watch=false --ci --runTestsByPath src/shared/components/form/numberField/__tests__/numberField.test.tsx
npx playwright test --config e2e/playwright.config.ts e2e/specs/number-field-layout.spec.ts
```
