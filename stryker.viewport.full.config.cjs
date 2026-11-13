const focused = require('./stryker.viewport.config.cjs');
const common = require('./tests/mutation/stryker.common.cjs');

module.exports = {
    ...focused,
    jest: common.jest,
    tempDirName: '.stryker-tmp/viewport-full',
    jsonReporter: { fileName: 'reports/mutation/viewport-full/mutation.json' },
    htmlReporter: { fileName: 'reports/mutation/viewport-full/index.html' },
};
