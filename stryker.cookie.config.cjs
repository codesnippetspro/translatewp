const common = require('./tests/mutation/stryker.common.cjs');

module.exports = {
    ...common,
    mutate: [
        "src/apps/translatewp/index.tsx:183:0-186:0",
        "src/apps/translatewp/index.tsx:197:0-211:0"
    ],
    tempDirName: '.stryker-tmp/cookie',
    jsonReporter: { fileName: 'reports/mutation/cookie/mutation.json' },
    htmlReporter: { fileName: 'reports/mutation/cookie/index.html' },
};
