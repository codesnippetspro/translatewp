import { __ } from '@wordpress/i18n';

import { LanguagesSetup } from '@/apps/admin/components/Languages/Setup';
import { LanguagesSwitcher } from '@/apps/admin/components/Languages/Switcher';
import { TranslationsSettings } from '@/apps/admin/components/Translations/Settings';
import { TranslationsContext } from '@/apps/admin/components/Translations/Context';
import { TranslationsAdvancedStyle, TranslationsStyle } from '@/apps/admin/components/Translations/Style';
import { AiProviders } from '@/apps/admin/components/AiProviders';
import { SettingsConnection } from '@/apps/admin/components/Settings/Connection';
import { SettingsAdvanced } from '@/apps/admin/components/Settings/Advanced';
import { VisitorLanguage } from '@/apps/admin/components/SettingsPage/VisitorLanguage';
import { useQuery } from '@tanstack/react-query';

import { ProBadge } from '@/shared/components/pro/ProBadge';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { useTabHash } from '@/apps/admin/hooks/useTabHash';
import { PageHeader, PageTab, PageTabs } from '@/apps/admin/components/PageLayout';
import { canAdminister } from '@/shared/lib/access';

// Settings is the canonical home of every setting, in six tabs.
// The Pro badge shows only while the account has no Pro.
const AiModelsTabTitle = () =>
{
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    const entitled = connectStatus?.pro?.entitled === true;

    return (
        <span className="inline-flex items-center gap-2">
            {__('AI models', 'translate')}
            {!entitled && <ProBadge />}
        </span>
    );
};

export const SETTINGS_TABS = [
    {
        name: 'languages',
        title: __('Languages', 'translate'),
        content: (
            <div className="flex flex-col gap-12">
                <LanguagesSetup />
                <VisitorLanguage />
            </div>
        ),
    },
    {
        name: 'translation',
        title: __('Translation', 'translate'),
        content: (
            <div className="flex flex-col gap-8">
                <div className="flex flex-col gap-2">
                    <h3 className="font-semibold text-lg text-translatewp-neutral!">{__('Translation settings', 'translate')}</h3>
                    <p className="text-sm text-translatewp-muted!">{__('What is translated, how it reads, and what the translations know about your site.', 'translate')}</p>
                </div>
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
                    <TranslationsContext />
                    <TranslationsAdvancedStyle />
                    <TranslationsStyle />
                    <div className="lg:col-span-2"><TranslationsSettings /></div>
                </div>
            </div>
        ),
    },
    { name: 'switcher', title: __('Switcher', 'translate'), content: <LanguagesSwitcher /> },
    { name: 'connection', title: __('Connection', 'translate'), content: <SettingsConnection /> },
    { name: 'advanced', title: __('Advanced', 'translate'), content: <SettingsAdvanced /> },
    {
        name: 'ai-model',
        title: (<AiModelsTabTitle />) as unknown as string,
        content: (
			<div className="flex flex-col gap-8">
				<div className="flex flex-col gap-2">
					<h3 className="font-semibold text-lg text-translatewp-neutral! m-0">
						{__('AI models', 'translate')}
					</h3>
					<p className="text-sm text-translatewp-muted!">
						{__('Choose the AI models used to translate your content.', 'translate')}
					</p>
				</div>
				<AiProviders embedded />
			</div>
		),
    },
] as PageTab[];

// What a user who manages translations by grant is given: the translation
// settings and the switcher settings. Everything else is an administrator's.
const GRANTED_TABS = ['translation', 'switcher'];

export const settingsTabsFor = (administers: boolean): PageTab[] =>
    administers ? SETTINGS_TABS : SETTINGS_TABS.filter(tab => GRANTED_TABS.includes(tab.name));

export const SettingsPage = () =>
{
    const administers = canAdminister();
    const tabs = settingsTabsFor(administers);
    const { activeTabName, onSelectTab } = useTabHash(tabs.map(tab => tab.name), tabs[0].name);

    return (
        <div className="flex flex-col">
            <PageHeader
                title={__('Settings', 'translate')}
                description={administers ? __('Every TranslateWP setting lives here.', 'translate') : __('The translation and switcher settings of this site.', 'translate')}
            />

            <PageTabs tabs={tabs} activeTabName={activeTabName} onSelect={onSelectTab} />
        </div>
    );
};
