const common = require('./tests/mutation/stryker.common.cjs');

module.exports = {
    ...common,
    mutate: ['src/shared/lib/translations/runTranslationTask.ts'],
    jest: {
        ...common.jest,
        config: {
            ...common.jest.config,
            testMatch: ['<rootDir>/src/shared/lib/translations/__tests__/runTranslationTask.test.ts'],
        },
    },
    tempDirName: '.stryker-tmp/task-control',
    jsonReporter: { fileName: 'reports/mutation/task-control/mutation.json' },
    htmlReporter: { fileName: 'reports/mutation/task-control/index.html' },
};
