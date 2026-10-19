const { createRequire } = require('module');
const { dirname } = require('path');

const wordpressScriptsConfigPath = require.resolve(
    '@wordpress/scripts/config/jest-unit.config'
);
const wordpressScriptsJestConfig = require(wordpressScriptsConfigPath);
const wordpressScriptsRequire = createRequire(wordpressScriptsConfigPath);

module.exports = {
    ...wordpressScriptsJestConfig,
    moduleNameMapper: {
        '\\.(css|scss)$': '<rootDir>/tests/style-mock.js',
        '^@/(.*)$': '<rootDir>/src/$1',
    },
    modulePathIgnorePatterns: ['<rootDir>/.claude/worktrees/'],
    preset: dirname(
        wordpressScriptsRequire.resolve(
            '@wordpress/jest-preset-default/package.json'
        )
    ),
    testEnvironment: 'jsdom',
};
