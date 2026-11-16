const common = require('./tests/mutation/stryker.common.cjs');

module.exports = {
    ...common,
    mutate: [
        "src/apps/translatewp/index.tsx:300:0-360:0",
        "src/shared/lib/translations/CacheFirstTranslator.ts",
        "src/shared/lib/translations/runTranslationTask.ts",
        "src/shared/lib/translations/missReportBackoff.ts",
        "src/shared/lib/translations/TranslationEngine.ts:18:0-19:0",
        "src/shared/lib/translations/TranslationEngine.ts:213:0-293:0",
        "src/shared/lib/translations/TranslationEngine.ts:358:0-410:0",
        "src/shared/lib/translations/TranslationEngine.ts:442:0-453:0",
        "src/shared/lib/translations/TranslationSession.ts:109:0-260:0",
        "src/shared/lib/translations/viewportText.ts"
    ],
    jest: {
        ...common.jest,
        config: {
            ...common.jest.config,
            testMatch: [
                '<rootDir>/src/apps/translatewp/__tests__/switcherSurfaces.test.tsx',
                '<rootDir>/src/shared/lib/translations/__tests__/viewportText.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/runTranslationTask.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/TranslationSession.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/cacheFirstTranslator.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/visitorFailOpen.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/servedTiming.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/ServedTranslationTiming.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/TranslationSessionTiming.test.ts',
                '<rootDir>/src/shared/lib/translations/__tests__/TranslationCancellationTiming.test.ts',
            ],
        },
    },
    tempDirName: '.stryker-tmp/viewport',
    jsonReporter: { fileName: 'reports/mutation/viewport/mutation.json' },
    htmlReporter: { fileName: 'reports/mutation/viewport/index.html' },
};
