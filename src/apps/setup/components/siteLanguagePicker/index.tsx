import { __ } from '@wordpress/i18n';

import { LanguageSelect } from '@/shared/components/languageSelect';
import { useSetupStore } from '@/apps/setup/stores/setupStore';

/**
 * The default language picker, the same control the Languages step used,
 * placed on the connection line once the site was read so it stays in
 * reach on every later step.
 */
export const SiteLanguagePicker = () =>
{
    const defaultLanguage = useSetupStore(state => state.defaultLanguage);
    const setDefaultLanguage = useSetupStore(state => state.setDefaultLanguage);

    return (
        <div className='ml-auto w-72 shrink-0' data-site-language-picker title={__('The language your content is written in. Every translation is made from it.', 'translate')}>
            <LanguageSelect compact defaultValue={defaultLanguage} onChange={setDefaultLanguage} placeholder={__('Change', 'translate')} />
        </div>
    );
};
