const common = require('./tests/mutation/stryker.common.cjs');

module.exports = {
    ...common,
    mutate: [
        "src/apps/translatewp/index.tsx",
        "src/shared/lib/translations/CacheFirstTranslator.ts",
        "src/shared/lib/translations/ServedTranslationTiming.ts",
        "src/shared/lib/translations/TranslationEngine.ts",
        "src/shared/lib/translations/TranslationSession.ts",
        "src/shared/lib/translations/viewportText.ts"
    ],
    tempDirName: '.stryker-tmp/viewport',
    jsonReporter: { fileName: 'reports/mutation/viewport/mutation.json' },
    htmlReporter: { fileName: 'reports/mutation/viewport/index.html' },
};
