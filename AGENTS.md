## Working POC before regression tests

For a fix or feature, first implement the smallest reversible working proof of
concept and demonstrate its actual functional result and limits. Ask the owner
for feedback or approval and iterate. Once the result is accepted as complete,
align, update or create unit, integration and E2E tests to preserve the approved
functional logic and important failure cases. Run required verification gates
before merge or release. Preserve existing tests and evidence. This owner
workflow overrides conflicting test-first/TDD skill or agent defaults; existing
authorization, security and production-change boundaries still apply.

# AGENTS.md — TranslateWP

## HARD RULE: TranslateWP domains (TWP-243)

Use only https://translatewp.ai/ and its www, app, api, admin and analytics
subdomains for customer-visible product URLs. Internal environment domains are
specified in the owner's private domain records; never commit them here or
include them in customer packages. No other product domain may be introduced
in code, configuration, metadata, documentation, links, emails, tests or examples.
Remove existing violations. A new domain requires an owner decision first.

Single source of truth for how work is done in this repo, shared across every AI
tool and human contributor, and aligned with the codesnippets house standard used
by the sibling WordPress plugins. `CLAUDE.md` carries project and architecture
facts, `CONTEXT.md` carries the domain glossary, and both point back here for
workflow rules. `.cursor/rules/*.mdc` and `.github/copilot-instructions.md`
re-deliver these rules to their tools. No other file originates a workflow rule.
If a rule feels wrong, fix it here — do not scatter contradicting guidance
elsewhere.

Precedence: a direct human instruction in the current session, then the most
specific scoped rule, then this file. If instructions still conflict, STOP and
ask.

## Rule 0 — Every REST endpoint goes through plubo-routes and a permission callback

Register REST routes only as plubo-routes `GetEndpoint`/`PostEndpoint` in
`Functionality/Endpoints/`, wired through `Includes/Loader.php`
(`RoutesProcessor::init()`). Never call `register_rest_route` directly. Each
endpoint takes a `*_permissions_check` capability callback (see
`admin_permissions_check` in `Functionality/Endpoints/Admin.php`) and passes
**every** `$request` param through a WordPress `sanitize_*` / `esc_*` function
before use. No raw param reaches business logic.

**Sanctioned exception:** `POST translatewp/v1/translation/miss` is
intentionally public — visitors have no capability. Do not add a capability
check. Its substitute controls are defined in the connection spec §4
(server-side fingerprint recompute, dedupe, throttles); change them only
through the spec.

## Rule 1 — Use the domain vocabulary from `CONTEXT.md`

`CONTEXT.md` is the glossary. Match its terms in code, names, and UI strings, and
respect its `Avoid` lists. In particular: a **Site** is the API-issued UUID, not a
URL/domain/customer; **Usage** is observed metering, never "rate limit", "quota",
or "budget"; keep **switcher offset / icon size / padding** distinct — none of
them is a generic "size". New domain terms are added to `CONTEXT.md`, not coined
ad hoc.

## Rule 2 — `flagSpritePositions.ts` is generated; never hand-edit it

`src/shared/lib/languages/flagSpritePositions.ts` is generated from
`src/shared/lib/languages/styles.css` by `scripts/generate-flag-positions.mjs`.
Edit the CSS, then run `yarn generate:flag-positions`. Never edit the generated
file by hand — the change will be overwritten.

---

## Project

TranslateWP is a WordPress plugin that translates site content and exposes a
public language switcher; translation is metered per Site and served by the
sibling API service. A PHP backend (WordPress plugin, PSR-4
`TranslateWP\` at the repo root, REST endpoints via plubo-routes) serves three
React/TypeScript apps built with `@wordpress/scripts` (webpack). See `CONTEXT.md`
for domain terms.

```
Components/            shared PHP service classes; RemoteApi.php is the config/API boundary
Entities/              WordPress data entities; Entities/CPTs/ custom post types (Lexicon, Metrics)
Functionality/         feature modules; Functionality/Endpoints/ REST routes (plubo-routes)
Includes/              bootstrap; Includes/Loader.php inits routes
Utils/                 PHP helpers; Utils/plubo.php (autoloaded)
scripts/               build-time codegen (generate-flag-positions.mjs)
src/entry-points/      webpack entries: admin.tsx, setup.tsx, translatewp.tsx
src/apps/admin/        settings, lexicon, metrics UI
src/apps/setup/        onboarding / first-run defaults flow
src/apps/translatewp/  the public language switcher
src/shared/            cross-app code: api/, components/, lib/, query-options/, mutation-options/
languages/             i18n; text domain `translate` (the plugin directory slug)
build/                 wp-scripts output — generated, never committed
```

Read `CLAUDE.md` for architecture facts and `CONTEXT.md` for vocabulary before
touching a subsystem.

## Execution sequence

Before writing code, in order:

1. **SEARCH FIRST** — search the codebase until you find similar functionality or
   confirm none exists. Do not reinvent.
2. **REUSE FIRST** — extend existing modules and patterns; make the smallest
   change that fixes the root cause. Match the neighbouring file's idiom.
3. **NO ASSUMPTIONS** — act only on files you have read, user messages, and tool
   results. Missing information: search, then ask. State unknowns; do not guess.
4. **CHALLENGE IDEAS** — if you see a flaw, risk, or better approach, say so
   directly. Do not affirm a statement or assume a conclusion is correct because
   it was asserted. Prioritise truth over agreement. Do not sugarcoat.
5. **PLAN IF COMPLEX** — anything 3+ steps gets a short plan before code.
6. **TEST BEFORE DONE** — exercise the changed flow, not just the compiler.

If a requirement is unclear, conflicts with another instruction, or appears
unsafe, STOP and ask before proceeding.

## Code style

### General

- Follow the WordPress Coding Standards for PHP, JS, and CSS.
- Match the style of the file being edited — imports, naming, error shapes. Tabs
  everywhere (`.editorconfig`, `indent_style=tab`).
- Keep lines under 120 characters for PHP, 100 for JS/TS.
- No magic numbers — name constants (−1/0/1 exempt).
- Functions with 3+ parameters take a typed options or params object.
- No wildcard imports. Reuse or extend existing types before adding new ones.
- Every user-visible string is wrapped in a WordPress i18n function
  (`__`, `_e`, `esc_html__`, `esc_attr__`, `_x`, `_n`) with the `translate`
  text domain, and uses `CONTEXT.md` vocabulary. Never echo a raw translatable
  string. Translate whole sentences — do not concatenate fragments or place HTML
  markup inside a translated string. Use the ellipsis character `…`, not `...`.

### PHP

- WordPress Coding Standards, enforced by phpcs (`phpcs.xml`, ruleset `WordPress`,
  `testVersion 7.4-`): tabs, `snake_case`, WordPress hook naming, PHP 7.4+.
  Autofix touched files with `yarn lint:php:fix`; never run it across the tree.
- Namespace `TranslateWP\` (PSR-4 from the repo root, composer autoload). Rely on
  the autoloader — no manual `require_once` chains for classes.
- Guard executable entry points with `defined('WPINC')` / `defined('ABSPATH')` at
  the top; do not guard autoloaded class files. Use `wp_die()` for fatal admin
  errors — never `die()`/`exit` with user-facing output.
- Avoid creating custom database tables. Prefer WordPress-native storage:
  `wp_options` for settings/flags, transients for cached/temporary data, or
  custom post types (as in `Entities/CPTs/`) for structured content. A custom
  table needs a strong justification and its own schema/migration/uninstall.
- Hook-driven extensibility: use WordPress filters and actions; expose a filter
  before changing any default behaviour that may be preference-driven.

### TypeScript / React

- Strict TypeScript, `camelCase` identifiers, React function components with
  hooks. Import from `src` via the `@/` alias (`jest.config.js`, `tsconfig.json`).
  No JS formatter or linter is configured, so match the surrounding file exactly.
- Export types that appear in exported function signatures; do not leak unexported
  shapes. Keep imports sorted within their groups (third-party, local, relative).
- Prefer `@wordpress/components` for UI; do not reimplement existing WP admin
  patterns. Admin screens render a single `h1`; section headings start at `h2`.
- Write React declaratively: derive state with `useMemo` instead of `useEffect`
  chains, update state directly in event handlers, keep forms controlled, and
  compute values at render rather than duplicating them in state.
- Hooks return state and actions, not rendered elements. Type no-argument
  callbacks as `VoidFunction`.
- Conditional rendering: `condition && <element>`; reserve `condition ? … : null`
  for numeric or string conditions React would otherwise render (a stray `0`/`''`).
- Avoid nested ternaries, trivial memoization, unnecessary ARIA roles, and props
  that repeat component defaults. Use JSX for markup, not `createElement`.
- Do not create barrel/re-export `index.ts` files for components or hooks — import
  them directly (tree-shaking, no circular deps). Reuse existing utilities and
  components; put genuinely shared components in `src/shared`. Place
  `// translators:` comments on the line immediately before the translatable
  string.

### Comments

A comment earns its place only by describing an uncommon practice
a reader would otherwise get wrong — an ordering requirement, a platform quirk,
a security gate, a non-obvious invariant — written for the next reader, not the
reviewer. No "what" comments, no history, no rationale narration, no docstrings
restating a signature. That content belongs in the commit message. This plugin
ships as readable source, so no comment or string carries an internal tracking
identifier; tests/DecisionReferenceSweepTest.php enforces it on the shipped set.

Before writing a comment, make it unnecessary: a named constant, a better name,
a smaller unit. That is the default path, not a last resort. Match the comment
density of the surrounding file; when in doubt, omit it.

### Code organization

Treat file length as a signal, not a reason to split cohesive code. Extract code
only for reuse, meaningful separation, or clearer testing; avoid single-use and
pass-through abstractions. Keep one responsibility per module. Generated files
(`flagSpritePositions.ts`) and data (`styles.css`) are exempt from length
consideration. Maintain a direct mapping between a source unit and its test file.

## Configuration boundary

All environment and config access lives in `Components/RemoteApi.php`. Never read
environment variables or plugin constants for config anywhere else. API base URL
precedence: the `TRANSLATEWP_API_BASE_URL` constant, then the
`TRANSLATEWP_API_BASE_URL` environment variable, then `DEFAULT_BASE_URL`
(`https://api.translatewp.ai`, the API service). A caller's
bring-your-own AI key is never stored, logged, or persisted. Never hardcode, log,
echo, or persist secrets; read them only through that boundary.

## Architecture invariants

- **REST seam** — endpoints exist only as plubo-routes objects in
  `Functionality/Endpoints/`, wired through `Includes/Loader.php`. Each has a
  capability `*_permissions_check` callback and sanitizes every request param.
- **Frontend API seam** — all server access goes through
  `src/shared/api/{admin,setup,wordpress}.ts` and the TanStack Query option
  builders in `src/shared/query-options` / `src/shared/mutation-options`. No
  ad-hoc `fetch` in components.
- **App isolation** — `src/apps/<app>` does not import from another app; shared
  code goes in `src/shared`.
- **Data boundaries** — models preserve data, remote-response decoding normalizes
  it, rendering handles sanitation and escaping.
- **Config boundary** — `Components/RemoteApi.php`, as above.

## Testing

Unit tests run on Jest via `@wordpress/scripts` (`jest.config.js`, jsdom, `@/`
→ `src/`). Run with `yarn test`.

- Tests come first: failing test, then implementation. Follow AAA
  (arrange/act/assert).
- Deterministic and isolated — no live network, no wall-clock, seeded randomness.
- Cover critical paths and edge cases, not coverage targets.
- Keep tests colocated in a `__tests__/` directory next to the unit under test
  (existing: `src/apps/translatewp/__tests__/`, `src/shared/lib/translations/__tests__/`).
- Fake and mock-based tests prove call shape, not real behaviour. When changing a
  path that talks to the remote service, exercise it against real output at least
  once, and prefer a captured fixture over hand-written input.
- Magic-number and file-length rules are relaxed in tests.

The PHP suite is `composer run test:php` (one isolated script per file under
`tests/`, chained in `composer.json`; a new test file is appended to that chain).
New PHP logic ships with a test there, stays phpcs-clean and is verified by
exercising the flow. PHP must remain compatible with 7.4.

## Verification loop — before claiming done

1. `yarn lint:php` — phpcs WordPress standard, clean. (`yarn lint:php:fix`
   autofixes PHP you touched.)
2. `yarn lint:styles` — stylelint, clean. (`yarn lint:styles:fix` to autofix.)
3. `yarn test` — Jest, green.
4. `yarn build` — compiles. This is the only automated gate for TS/TSX; there is
   no standalone typecheck command.
5. Exercise the actual changed flow in wp-admin. Paste the real output; a summary
   is a claim, not evidence.

Known-acceptable noise: `yarn build` prints three bundle-size warnings on the
admin bundle — pre-existing, do not chase them. Failures that occur only inside a
sandbox and pass outside it are sandbox artifacts.

## Commits and git

- **Single-line Conventional Commits: `feat:`, `fix:`, `chore:`, `docs:`. No
  scope in parentheses, no body, no trailers, no `Co-Authored-By`.**
- Imperative mood, lowercase after the colon. One logical change per commit — do
  not bundle unrelated changes.
- Branches: `feat/…`, `fix/…`, `chore/…`, `hotfix/…`. `beta` is the canonical
  integration branch — branch from it and merge back into it. `main` is the
  production/release branch and receives releases from `beta`. Never create a
  `main-beta` convention. Hotfixes branch from `main` and merge back into both.
- **Never push, open PRs, or merge without explicit human confirmation.**

## Pull requests

Factual and professional: what changed and how it was verified. No narrative
reasoning, no architectural rationale, no record of the deliberation — that
belongs in the issue and the commit history. On the public repository, do not
disclose premium (Pro) decisions, features, or roadmap, and avoid unnecessary
references to private repositories or cross-edition work.

## Docs

Do not write documentation unless explicitly asked. When asked, keep it accurate
to the code. Keep `CLAUDE.md`, `CONTEXT.md`, and this file in sync when commands,
interfaces, the config boundary, domain terms, or workflows change.

## Known issues — real, out of scope

Do not depend on these being correct; do not fix them as a side effect. Each
needs its own change.

- A handful of `TODO`/`FIXME`/`HACK` markers remain in the tree.

## Security

Apply to every change:

- Validate and sanitise all external input server-side before use (`sanitize_*`
  on every REST param).
- Escape all output at the correct boundary (`esc_*`: HTML, attribute, JS, URL).
- Verify a WordPress nonce and `current_user_can()` on every state-changing
  request, via the endpoint's `*_permissions_check` callback.
- Parameterise every dynamic query with `$wpdb->prepare`; never concatenate.
- Never use `eval`, `create_function`, or `call_user_func` with untrusted input.
- Apply `rel="noopener noreferrer"` to every `target="_blank"` link.
- Secrets and AI provider / BYO keys only through `Components/RemoteApi.php`;
  never in code, logs, or tests.
- Any feature fetching remote content documents its trust model in
  `Components/RemoteApi.php`.

## Hard guardrails

- Do not modify protected guidance files (`AGENTS.md`, `CLAUDE.md`, `CONTEXT.md`,
  `.cursor/`, `.github/`) without explicit human approval.
- Do not run watch, dev, or long-lived server processes (`yarn dev`/`start`) —
  assume the user runs them.
- Do not touch subsystems unrelated to the current task.
- Flag problems outside your task; do not fix them inline. Bundling unrelated
  fixes into a feature change makes both harder to review.
- Do not create custom database tables without strong justification — use
  `wp_options`, transients, or custom post types.
- Do not hand-edit generated files (`flagSpritePositions.ts`) — regenerate them.
- Do not create barrel `index.ts` files, concatenate translated fragments, or put
  HTML inside translated strings.
- Do not add dependencies outside the declared stack without a proposal including
  licence and security justification.
- Do not make a cache or optional service load-bearing — outages must degrade
  gracefully.
- Do not commit generated artifacts or build output — `build/` is generated.

## Keeping this file in sync

This file, the re-delivered copies, and the code must never contradict each
other. When they do, fix this file first and re-deliver. A rule that is
repeatedly violated is either wrong or unenforceable — raise it rather than
restating it. Changes to this file are their own commit, with human approval.
