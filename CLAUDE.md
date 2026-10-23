## Implementation workflow

For user-facing work, follow the working-POC, owner-feedback and test-freeze
sequence in `AGENTS.md`. It takes precedence over conflicting test-first/TDD
defaults. Existing tests and required final verification remain in force.

The POC and owner-feedback checkpoint applies primarily to user-facing surfaces.
Backend, API and internal operations work may proceed through normal PR review
and appropriate unit, integration and CI verification without a separate POC
approval pause. Existing security, production and release approval boundaries
remain unchanged.

# TranslateWP

## HARD RULE: TranslateWP domains (TWP-243)

Use only https://translatewp.ai/ and its www, app, api, admin and analytics
subdomains for customer-visible product URLs. Internal environment domains are
specified in the owner's private domain records; never commit them here or
include them in customer packages. No other product domain may be introduced
in code, configuration, metadata, documentation, links, emails, tests or examples.
Remove existing violations. A new domain requires an owner decision first.

A WordPress plugin that translates site content and exposes a public language
switcher; hosted translation is metered per Site. PHP backend (WordPress plugin,
PSR-4 `TranslateWP\`, REST endpoints via plubo-routes) plus three
React/TypeScript apps built with `@wordpress/scripts`.

> **Workflow rules live in [`AGENTS.md`](AGENTS.md)** — execution sequence, commit
> convention, verification loop, guardrails, and the repo-specific rules at the
> top (REST-endpoint seam, domain vocabulary, generated flag data). Domain terms
> live in [`CONTEXT.md`](CONTEXT.md). This file carries project and architecture
> facts only. If a workflow rule feels wrong, fix it in AGENTS.md, not here.

## Layout

- `Components/` — shared PHP service classes; `RemoteApi.php` is the config/API boundary.
- `Entities/` — WordPress data entities; `Entities/CPTs/` custom post types (e.g. Metrics).
- `Functionality/` — feature modules; `Functionality/Endpoints/` holds REST routes.
- `Includes/` — bootstrap; `Includes/Loader.php` runs `RoutesProcessor::init()`.
- `Utils/` — PHP helpers; `Utils/plubo.php` is autoloaded via composer.
- `scripts/` — build-time codegen (`generate-flag-positions.mjs`).
- `src/entry-points/*.tsx` — webpack entries: `admin`, `setup`, `translatewp`.
- `src/apps/admin/` — settings, lexicon, metrics. `src/apps/setup/` — onboarding /
  first-run defaults. `src/apps/translatewp/` — the public switcher.
- `src/shared/` — cross-app code: `api/`, `components/`, `lib/`, `query-options/`, `mutation-options/`.
- `languages/` — i18n, text domain `translate`. `build/` — generated output.

## Build & test

```bash
yarn install && composer install   # deps (Node >=16, PHP >=7.4)
yarn lint:php                      # phpcs WordPress standard (yarn lint:php:fix to autofix)
yarn lint:styles                   # stylelint (yarn lint:styles:fix to autofix)
yarn test                          # Jest via @wordpress/scripts (jsdom)
yarn build                         # wp-scripts webpack build — the only TS/TSX gate
yarn generate:flag-positions       # regenerate flagSpritePositions.ts from styles.css
```

No standalone typecheck. See AGENTS.md for the verification loop.

## Architecture

- Requests enter through REST endpoints registered as plubo-routes
  `GetEndpoint`/`PostEndpoint` in `Functionality/Endpoints/`, each guarded by a
  `*_permissions_check` capability callback.
- Routes are collected and initialised in `Includes/Loader.php`
  (`\PluboRoutes\RoutesProcessor::init()`).
- The React apps call the server only through `src/shared/api/{admin,setup,wordpress}.ts`,
  wrapped in TanStack Query option builders; Zustand holds app state. Imports
  resolve `@/` → `src/`.
- The plugin reaches its remote translation service through
  `Components/RemoteApi.php`, the single place environment/config is read
  (default base `https://api.translatewp.ai`).
- `src/shared/lib/languages/flagSpritePositions.ts` is generated from `styles.css`.
- PHP is namespaced `TranslateWP\` (PSR-4 from repo root, composer autoload).

## Security

- REST auth/capability lives in each endpoint's `*_permissions_check` callback.
- Config and secrets are read only in `Components/RemoteApi.php`.

## Code style & workflow

See [`AGENTS.md`](AGENTS.md). Domain vocabulary: [`CONTEXT.md`](CONTEXT.md).
