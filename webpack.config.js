const defaultConfig = require('@wordpress/scripts/config/webpack.config');

const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        shared: path.resolve(__dirname, 'src/shared/style.css'),
        setup: path.resolve(__dirname, 'src/entry-points/setup.tsx'),
        admin: path.resolve(__dirname, 'src/entry-points/admin.tsx'),
        'network-admin': path.resolve(__dirname, 'src/entry-points/networkAdmin.tsx'),
        translatewp: path.resolve(__dirname, 'src/entry-points/translatewp.tsx'),
        editor: path.resolve(__dirname, 'src/entry-points/editor.tsx')
    },
    watchOptions: {
        ignored: ['**/build/**', '**/node_modules/**', '**/.git/**'],
    },
    resolve: {
        ...defaultConfig.resolve,
        alias: {
            ...defaultConfig.resolve.alias,
            '@': path.resolve(__dirname, 'src')
        },
    }
};
