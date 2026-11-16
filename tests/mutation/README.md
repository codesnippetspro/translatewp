# Accepted translation behaviour checks

The normal JavaScript suite and `composer test:php` run the regression tests.
Mutation tools are development dependencies and do not belong in the plugin zip.
Use the repository's Node version and PHP 8.3 or newer, with Xdebug for Infection.
Run one mutation worker at a time, without a watch process. The runner is
restarted after 25 mutant runs to bound its heap and discard cross-mutant state.

```sh
CI=true npm run test:mutation:cookie
CI=true npm run test:mutation:viewport
XDEBUG_MODE=coverage vendor/bin/infection Functionality/Endpoints/Scanning.php --configuration=infection.json --with-uncovered --threads=1
```

The cookie profile mutates the cookie-clear and persistence functions. The
viewport profile mutates the notice, session cancellation, loading cleanup,
cache lookup, miss retry and bounded-task paths. Its ten related suites are
listed explicitly in the profile; kills are counted only from those suites.
`stryker.viewport.full.config.cjs` retains the complete related-test selection
for follow-up verification against the same production ranges.

Always retain `--with-uncovered`: Infection 0.34 defaults to mutating covered code only.
The complete mutation population must include unexecuted branches as uncovered.

Infection executes the real `Scanning` class in process through PHPUnit. Its
WordPress fixture replaces external storage, scheduling and HTTP boundaries;
it does not replace the scan implementation. The regular PHP script suite
continues to run unchanged alongside these tests.

Raw JSON and HTML reports are written under `reports/mutation/`. Review every
surviving and uncovered mutant individually. Record a reproducing assertion or
a specific equivalence reason, keyed by source location, operator and
replacement. Report assertion kills separately from timeouts and errors.
A timeout needs repeatable hang evidence before being treated as detection.
Do not infer a kill from a test that was not actually run against the mutant.

For a full-suite follow-up that reuses only assertion kills, preserve the
initial report and generate a separate incremental cache:

```sh
node tests/mutation/killed-cache.mjs reports/mutation/viewport/mutation.json reports/mutation/viewport/killed-cache.json
CI=true node_modules/.bin/stryker run stryker.viewport.full.config.cjs --incremental --incrementalFile reports/mutation/viewport/killed-cache.json
```

All earlier survivors, uncovered mutants, timeouts and errors are removed from
that cache, so they must run again. Stryker additionally invalidates a cached
kill when its source or killing tests changed. Record the tool's reused result
count and the hashes of the original report and generated input cache. Keep a
copy of the generated cache before the run; Stryker updates its incremental
file with the new results.

When comparing repeated runs, list every changed mutant status by source
location, operator and replacement. Keep the prior reports and distinguish
new assertions, runner errors and timeout evidence; do not silently replace
a prior result with the newer status.

For supplementary task-timeout evidence, run the six fast task cases alone:

```sh
CI=true node_modules/.bin/stryker run stryker.task-control.config.cjs
```

This control is separate from the full viewport profile. Match its mutations
by file, location, operator and replacement; retain the original full-profile
statuses and counts, and list the control result separately for each timeout.
A control assertion kill does not turn a main-profile Timeout into Killed.
