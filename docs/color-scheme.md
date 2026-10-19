# Colour scheme

The user portal (`translatewp-app`, `src/app/globals.css`) is the source of truth. The plugin's tokens in `src/shared/tailwind.css` carry the same values; `src/shared/__tests__/colorTokens.test.ts` fails when they drift.

| Role | Portal token | Value | Plugin token |
|---|---|---|---|
| Primary (brand blue, links, selected) | `--color-translatewp-primary` | `#0e3ecd` | `--color-translatewp-primary` |
| WordPress admin accent | `--color-translatewp-admin` | `#2271B1` | `--color-translatewp-admin` (was `#0e3ecd`) |
| Foreground text | `--color-translatewp-neutral` | `#343739` | `--color-translatewp-neutral` |
| Muted text | `--color-translatewp-muted` | `#646970` | `--color-translatewp-muted` |
| Border, divider | `--color-translatewp-border` | `#E2E2E4` | `--color-translatewp-border` |
| Icons | `--color-translatewp-icons` | `#9CA1A8` | `--color-translatewp-icons` |
| Selection, raised surface | `--color-translatewp-selection` | `#F6F7F7` | `--color-translatewp-selection` |
| Drag grip | `--color-translatewp-grip` | `#8D8F95` | `--color-translatewp-grip` |
| Success | `--color-twp-success` | `#45A366` | `--color-translatewp-success` (was `#2E7D57`) |
| Warning | `--color-twp-warning` | `#c98a1e` | `--color-translatewp-warning` (new) |
| Call to action green | `--color-twp-cta` | `#2E7D57` | `--color-translatewp-cta` (new, unused until a surface needs it) |
| Call to action hover | `--color-twp-cta-hover` | `#276B4A` | `--color-translatewp-cta-hover` (new) |
| Error | `--color-translatewp-error` | `#FF0000` | `--color-translatewp-error` (`#F00`, the short form stylelint asks for) |
| Card surface | `--color-twp-neutral-0` | `#ffffff` | `bg-white` |

Rules

- Plugin screens use tokens, never Tailwind palette colours (`text-red-600`, `bg-amber-50`) and never raw hex outside the token file. Flags (`src/shared/lib/languages/styles.css`) and the brand mark keep their own fills.
- CSS fallbacks (`var(--color-translatewp-x, #hex)`) repeat the token's value exactly.
- The visitor-facing switcher (`src/apps/translatewp/SwitcherView.tsx`) ships its own inline stylesheet with the border value written out, because the admin tokens are not loaded on the front end; its hex must equal `--color-translatewp-border`.
- Portal-only scales (`--color-twp-neutral-*`, `--color-twp-primary-500`) have no plugin counterpart; the plugin's WordPress chrome supplies those surfaces.
