const common = require('./tests/mutation/stryker.common.cjs');

module.exports = {
    ...common,
    mutate: [
        "src/apps/translatewp/index.tsx:300:0-360:0",
        "src/shared/lib/translations/CacheFirstTranslator.ts",
        "src/shared/lib/translations/runTranslationTask.ts",
        "src/shared/lib/translations/TranslationEngine.ts:218:0-281:0",
        "src/shared/lib/translations/TranslationSession.ts:109:0-243:0",
        "src/shared/lib/translations/viewportText.ts"
    ],
    tempDirName: '.stryker-tmp/viewport',
    jsonReporter: { fileName: 'reports/mutation/viewport/mutation.json' },
    htmlReporter: { fileName: 'reports/mutation/viewport/index.html' },
};
