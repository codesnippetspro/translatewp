const common = require('./tests/mutation/stryker.common.cjs');

module.exports = {
    ...common,
    mutate: [
        "src/apps/translatewp/index.tsx"
    ],
    tempDirName: '.stryker-tmp/cookie',
    jsonReporter: { fileName: 'reports/mutation/cookie/mutation.json' },
    htmlReporter: { fileName: 'reports/mutation/cookie/index.html' },
};
