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

import { usePageHash } from '@/apps/admin/hooks/usePageHash';
import { PageBody, PageHeader, NavigationPage } from '@/apps/admin/components/PageLayout';
import { canAdminister } from '@/shared/lib/access';

export type SettingsPageDefinition = NavigationPage & {
	heading: string;
	description: string;
};

export const SETTINGS_PAGES = [
    {
        name: 'languages',
        title: __('Languages', 'translate'),
        heading: __('Languages', 'translate'),
        description: __('The language your content is written in, and the languages it is translated into.', 'translate'),
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
        heading: __('Translation settings', 'translate'),
        description: __('What is translated, how it reads, and what the translations know about your site.', 'translate'),
        content: (
            <div className="flex flex-col gap-8">
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:items-start">
                    <TranslationsContext />
                    <TranslationsAdvancedStyle />
                    <TranslationsStyle />
                    <div className="lg:col-span-2"><TranslationsSettings /></div>
                </div>
            </div>
        ),
    },
    {
        name: 'switcher', title: __('Switcher', 'translate'),
        heading: __('Language switcher', 'translate'),
        description: __('How the language switcher looks and where it shows. Every change shows in the preview first.', 'translate'),
        content: <LanguagesSwitcher />,
    },
    {
        name: 'connection', title: __('Connection', 'translate'),
        heading: __('Connection', 'translate'),
        description: __('Manage your site connection and reopen the setup wizard.', 'translate'),
        content: <SettingsConnection />,
    },
    {
        name: 'advanced', title: __('Advanced', 'translate'),
        heading: __('Advanced Settings', 'translate'),
        description: __('Manage the translation cache, uninstall preferences, and plugin data.', 'translate'),
        content: <SettingsAdvanced />,
    },
    {
        name: 'ai-model',
        title: __('AI models', 'translate'),
        heading: __('AI models', 'translate'),
        description: __('Choose the AI models used to translate your content.', 'translate'),
        content: <AiProviders embedded />,
    },
] as SettingsPageDefinition[];

// What a user who manages translations by grant is given: the translation
// settings and the switcher settings. Everything else is an administrator's.
const GRANTED_PAGES = ['translation', 'switcher'];

export const settingsPagesFor = (administers: boolean): SettingsPageDefinition[] =>
    administers ? SETTINGS_PAGES : SETTINGS_PAGES.filter(page => GRANTED_PAGES.includes(page.name));

export const SettingsPage = () =>
{
    const administers = canAdminister();
    const pages = settingsPagesFor(administers);
    const { activePageName } = usePageHash(pages.map(page => page.name), pages[0].name);
    const current = pages.find(page => page.name === activePageName);

    return (
        <div className="flex flex-col">
            <PageHeader
                title={current?.heading}
                description={current?.description}
            />

            <PageBody>{current?.content}</PageBody>
        </div>
    );
};
