## Implementation workflow

Follow the working-POC, owner-feedback and test-freeze sequence in `AGENTS.md`.
It takes precedence over conflicting test-first/TDD defaults. Existing tests and
required final verification remain in force.

# GitHub Copilot instructions — TranslateWP

## HARD RULE: TranslateWP domains (TWP-243)

Use only https://translatewp.ai/ and its www, app, api, admin and analytics
subdomains for customer-visible product URLs. Internal environment domains are
specified in the owner's private domain records; never commit them here or
include them in customer packages. No other product domain may be introduced
in code, configuration, metadata, documentation, links, emails, tests or examples.
Remove existing violations. A new domain requires an owner decision first.

This file re-delivers the repository ruleset to Copilot. The canonical version is
[`AGENTS.md`](../AGENTS.md); domain terms live in [`CONTEXT.md`](../CONTEXT.md).
If any two disagree, `AGENTS.md` wins — and the disagreement is a bug to fix there.

## Project

WordPress translation plugin with a metered hosted service and a public language
switcher. PHP backend (PSR-4 `TranslateWP\`, REST via plubo-routes) plus three
React/TypeScript apps built with `@wordpress/scripts`.

## Before writing code

1. **SEARCH FIRST** — find existing functionality before writing new.
2. **REUSE FIRST** — extend existing patterns; smallest change that fixes the
   root cause.
3. **NO ASSUMPTIONS** — act only on files read, user messages, and tool results.
4. **CHALLENGE IDEAS** — question assumptions; prioritise truth over agreement.
5. **PLAN IF COMPLEX** — 3+ steps gets a short plan.
6. **TEST BEFORE DONE** — exercise the changed flow.

## Style

- PHP: WordPress Coding Standards enforced by phpcs (`phpcs.xml`, PHP 7.4+), tabs,
  `snake_case`, lines < 120. Autofix touched files with `yarn lint:php:fix`. Guard
  entry points with `defined('WPINC')`/`ABSPATH`; `wp_die()` for admin errors.
  Avoid custom DB tables — use `wp_options`/transients/CPTs; extend via WP
  filters/actions.
- TS/TSX: strict TypeScript, tabs, `camelCase`, React function components, `@/` →
  `src/`, lines < 100. No JS linter configured — match the surrounding file exactly.
  Prefer `@wordpress/components`. Declarative React (`useMemo` over `useEffect`
  chains, controlled forms); hooks return state/actions; `VoidFunction` for no-arg
  callbacks; conditional render `cond && <el>`; no barrel `index.ts` files.
- No magic numbers; 3+ parameters take a typed options object. Reuse existing
  types/utilities/components first.
- User-facing strings translated with the `translate` text domain and using
  `CONTEXT.md` vocabulary (Site = UUID; Usage ≠ "rate limit"/"quota"). Translate
  whole sentences, no HTML inside strings, use `…`.
- Comments state a current constraint only — no narration, no history, no
  rationale. That belongs in the commit message.

## Seams that must not be bypassed

- REST endpoints: plubo-routes objects in `Functionality/Endpoints/` with a
  `*_permissions_check` callback; sanitize every request param. No direct
  `register_rest_route`.
- Frontend server access only through `src/shared/api/*`. Apps never import across
  apps; shared code in `src/shared`.
- Config/secrets read only in `Components/RemoteApi.php`.
- `src/shared/lib/languages/flagSpritePositions.ts` is generated — never hand-edit.

## Verification

`yarn lint:php` clean → `yarn lint:styles` clean → `yarn test` green →
`yarn build` compiles → exercise the changed flow in wp-admin. No standalone
typecheck; build is the only TS/TSX gate.

## Commits

Single-line Conventional Commits: `feat:`, `fix:`, `chore:`, `docs:`. No scope in
parentheses, no body, no trailers, no `Co-Authored-By`. Never push, open PRs, or
merge without explicit human confirmation.

## Non-negotiable guardrails

- Do not introduce secrets or AI provider / BYO keys into code or logs; read them
  only through `Components/RemoteApi.php`.
- Apply `rel="noopener noreferrer"` to every `target="_blank"` link; never use
  `eval`/`create_function`/`call_user_func` with untrusted input.
- Do not commit `build/` output, hand-edit generated files, or create custom DB
  tables without strong justification.
- Do not modify protected guidance files (`AGENTS.md`, `CONTEXT.md`, `.github/`,
  `.cursor/`) without explicit human approval.
- Do not run watch/dev/long-lived processes.
- Flag problems outside the current task; do not fix them inline.

## When uncertain

If a requirement is unclear, conflicts with another instruction, or appears
unsafe, STOP and ask before proceeding.
