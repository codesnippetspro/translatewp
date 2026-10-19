import type { NetworkAdminData } from '@/apps/network-admin/types';

export const networkAdminData: NetworkAdminData = {
    connection: {
        connected: true,
        accountLabel: 'owner@example.com',
        pro: {
            plan: 'pro', entitled: true, installed: false, active: false,
            installedVersion: null, latestVersion: '1.0.0', packagePending: false, accountUrl: 'https://portal.example.test/account', upgrade: null,
            updateAvailable: false, updateUrl: null,
            capabilities: { seo: true, custom_style: true, byok: true },
        },
        outcome: [],
    },
    subsites: [{
        id: 2, name: 'Editorial', url: 'https://editorial.example.com', host: 'editorial.example.com', siteId: 'site-2',
        connected: true, setupEnded: true, languages: ['it', 'fr'],
        dashboardUrl: '/site-2/dashboard', seoUrl: '/site-2/seo', lexiconUrl: '/site-2/lexicon',
    }],
    scope: {
        status: '',
        subsites: [{ id: 1, name: 'Main' }, { id: 2, name: 'Editorial' }, { id: 3, name: 'Shop' }],
        groups: [
            { id: 'account', label: 'The account connection', tier: 'network_only', switchable: false, state: 'locked', exceptions: {}, fields: [] },
            {
                id: 'uninstall', label: 'Delete data on uninstall', tier: 'network_only', switchable: false, state: 'locked', exceptions: {},
                fields: [{ key: 'advanced.delete_on_uninstall', label: 'Delete TranslateWP data when the plugin is deleted', type: 'boolean', default: false, options: [], value: false }],
            },
            {
                id: 'switcher_appearance', label: 'Switcher appearance', tier: 'network_default', switchable: true, state: 'default', exceptions: { '3': 'local' },
                fields: [
                    { key: 'languages.switcher.show_flag', label: 'Show flag icon', type: 'boolean', default: true, options: [], value: true },
                    { key: 'languages.switcher.regional_names', label: 'Regional language names', type: 'select', default: 'full', options: { full: 'Full name', short: 'Short' }, value: 'full' },
                    { key: 'languages.switcher.text_color', label: 'Text color', type: 'color', default: '#393535', options: [], value: '#393535' },
                    { key: 'languages.switcher.offset', label: 'Switcher offset', type: 'number', default: 24, options: [], value: 24 },
                    { key: 'languages.switcher.pending_message', label: 'Translating message', type: 'text', default: '', options: [], value: '', help: 'Shown while a page translates.', placeholder: 'Translating this page for you, a few more seconds.' },
                ],
            },
            {
                id: 'seo_technical', label: 'Technical SEO', tier: 'network_default', switchable: true, state: 'default', exceptions: {},
                fields: [
                    { key: 'languages.seo.output_hreflang', label: 'Output hreflang tags', type: 'boolean', default: true, options: [], value: true },
                    { key: 'languages.seo.sitemaps', label: 'Per-language sitemaps', type: 'boolean', default: true, options: [], value: true, pro: true },
                ],
            },
            {
                id: 'audience',
                label: 'Who sees translations',
                tier: 'network_default',
                switchable: true,
                state: 'default',
                exceptions: {},
                fields: [{ key: 'translations.settings.visible_roles', label: 'Show translations only to these roles', type: 'roles', default: [], options: { administrator: 'Administrator', editor: 'Editor' }, value: [], pro: true }],
            },
            {
                id: 'style_tone', label: 'Style and tone', tier: 'network_default', switchable: true, state: 'locked', exceptions: {},
                fields: [{ key: 'translations.style.formality', label: 'Formality', type: 'range', default: 5, options: [], value: 5 }],
            },
        ],
    },
    actions: {
        url: '/wp-admin/admin-post.php', connect: 'connect', scope: 'scope',
        nonces: { connect: 'a', scope: 'b' },
    },
    upgradeUrl: 'https://example.com/upgrade',
};
