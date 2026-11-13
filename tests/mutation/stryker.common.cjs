module.exports = {
    testRunner: 'jest',
    plugins: ['@stryker-mutator/jest-runner'],
    coverageAnalysis: 'perTest',
    concurrency: 1,
    maxTestRunnerReuse: 25,
    dryRunTimeoutMinutes: 5,
    timeoutMS: 60000,
    reporters: ['progress', 'clear-text', 'json', 'html'],
    jest: {
        projectType: 'custom',
        configFile: 'jest.config.js',
        enableFindRelatedTests: true,
        config: { ci: true, watch: false, watchAll: false, maxWorkers: 1 },
    },
    ignorePatterns: ['reports', '.infection', 'vendor', 'bundle', 'build', 'dist-archive'],
};
