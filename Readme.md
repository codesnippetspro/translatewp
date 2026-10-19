# TranslateWP

TranslateWP is a WordPress plugin that translates site content and exposes a
public language switcher. It pairs a PHP backend (a WordPress plugin using
PSR-4 `TranslateWP\` and REST endpoints via plubo-routes) with React and
TypeScript apps built with `@wordpress/scripts`. Translation work is metered
per site and served by a separate API service that the plugin reaches through
`Components/RemoteApi.php`.

## Requirements

- A WordPress site (a local WordPress environment is fine for development)
- PHP >= 7.4
- Composer
- Node.js >= 16
- Yarn

## Install dependencies

From the plugin root:

```bash
yarn install --frozen-lockfile && composer install
```

## Build

```bash
yarn build
```

Use `yarn start` for the development watcher while working on the UI.

## Verification gates

Run these before opening a pull request. They are the checks the project
expects to pass:

```bash
yarn lint:php
yarn lint:styles
yarn test
composer run test:php
yarn typecheck
yarn build
```

- `yarn lint:php` runs phpcs against the WordPress standard.
- `yarn lint:styles` runs stylelint.
- `yarn test` runs the Jest suite through `@wordpress/scripts`.
- `composer run test:php` runs the PHP test suite.
- `yarn typecheck` runs the TypeScript compiler in check mode.
- `yarn build` runs the webpack production build.

## Project layout

- `translatewp.php` boots the plugin, defines constants, loads Composer, and
  registers lifecycle hooks.
- `Components/` holds shared PHP service classes; `RemoteApi.php` is the single
  config and API boundary.
- `Functionality/` holds feature modules, including REST routes under
  `Functionality/Endpoints/`.
- `Entities/` defines WordPress data entities and the script and style entities
  the loader enqueues from `build/`.
- `Includes/Loader.php` loads core classes, attaches WordPress hooks, and runs
  `\PluboRoutes\RoutesProcessor::init()`.
- `src/` contains the React and TypeScript apps (`admin`, `setup`,
  `translatewp`) and shared UI in `src/shared/`.
- `build/` holds compiled assets produced by `@wordpress/scripts`.

## Contributing

- Open pull requests against the `beta` branch.
- Prefix commit subjects with `fix:`, `feat:`, `chore:`, or `docs:`.
- Keep the verification gates above green before requesting review.

