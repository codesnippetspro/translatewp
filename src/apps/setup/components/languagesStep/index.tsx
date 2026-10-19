import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Plus } from 'lucide-react';

import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { setupSlugs } from '@/shared/lib/languages/slugs';
import { LanguageChecklist, SelectedLanguageChips } from '@/shared/components/languageMultiselect';
import { LanguageOption } from '@/shared/components/languageOption';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { getIndexPages } from '@/shared/api/admin';
import { getQuota, type Quota } from '@/shared/api/quota';
import { quotaCostSentence } from '@/shared/lib/retranslationScope';
import { getSuggestedTargets } from '@/apps/setup/components/languagesStep/suggestedTargets';

export const LanguagesStep = () =>
{
    const { defaultLanguage, setDefaultLanguage, languages, setLanguages, spinePages, setSpinePages, siteRead } = useSetupStore();
    // The estimate before committing: the pages the read found, times the
    // languages chosen, against the quota available right now. A wizard that
    // resumes at this step holds no pages yet and reads them back from the site.
    const [quota, setQuota] = useState<Quota | null>(null);
    useEffect(() =>
    {
        let live = true;
        getQuota().then(result => { if (live) setQuota(result); }).catch(() => undefined);
        if (siteRead && spinePages.length === 0)
        {
            getIndexPages().then(response => { if (live) setSpinePages(response.pages); }).catch(() => undefined);
        }
        return () => { live = false; };
    }, []);


    // The default language is the source of every translation: the server adds
    // it on save, so it is never offered or counted as a translation language.
    const targets = languages.filter( ( locale ) => locale !== defaultLanguage );
    const setTargets = ( next: string[] ) => setLanguages( next.filter( ( locale ) => locale !== defaultLanguage ) );

    const preferredLocales = [
        ...( window.setupData?.userLocale ? [ window.setupData.userLocale ] : [] ),
        ...( typeof navigator !== 'undefined' ? navigator.languages ?? [] : [] ),
    ];
    const suggestions = getSuggestedTargets( defaultLanguage, preferredLocales ).filter(
        ( locale ) => locale !== defaultLanguage && !targets.includes( locale )
    );

    // Each add reads the list at click time, so two chips pressed before the
    // step re-renders both stay.
    const addLanguage = ( locale: string ) =>
    {
        const current = useSetupStore.getState().languages;
        if ( current.includes( locale ) ) {
            return;
        }
        setTargets( [ ...current, locale ] );
    };

    const activeLine = targets.length === 0
        ? __( 'No translation languages added yet.', 'translate' )
        : sprintf(
            /* translators: %d: number of target languages the site will be translated into */
            _n( '%d translation language active.', '%d translation languages active.', targets.length, 'translate' ),
            targets.length
        );
    const estimateLine = targets.length > 0 && spinePages.length > 0
        ? sprintf(
            /* translators: 1: pages read, 2: languages chosen, 3: the quota sentence */
            __( 'Translating %1$d pages into %2$d languages. %3$s', 'translate' ),
            spinePages.length,
            targets.length,
            quotaCostSentence( { pages: spinePages.length, languages: targets.length }, quota )
        )
        : '';

    return (
        <div className="setup-languages">
            <div className='setup-language-targets flex flex-col gap-2.5'>
                <h2 className='font-semibold'>{__( 'Add languages', 'translate' )}</h2>
                <div className='setup-language-target-grid' data-language-target-layout>
                    <div className='setup-language-summary' data-language-summary>
                        {suggestions.length > 0 && (
                        <div className='flex flex-col gap-1.5'>
                        <h3 className='text-xs! font-semibold text-translatewp-muted!'>{__( 'Suggested', 'translate' )}</h3>
                        <div className='flex flex-wrap gap-2'>
                            {suggestions.map( ( locale ) => (
                                <button
                                    type='button'
                                    key={locale}
                                    className='flex items-center gap-1.5 rounded-sm border border-translatewp-border bg-white px-2.5 py-1 cursor-pointer hover:border-translatewp-primary'
                                    onClick={() => addLanguage( locale )}
                                    aria-label={sprintf(
                                        /* translators: %s: language name */
                                        __( 'Add %s', 'translate' ),
                                        getLanguageNameFromLocale( locale ) ?? locale
                                    )}
                                >
                                    <Plus size={14} className='text-translatewp-icons' aria-hidden="true" />
                                    <LanguageOption countryCode={locale} />
                                </button>
                            ) )}
                        </div>
                        </div>
                        )}
                        <div className='flex flex-col gap-1.5'>
                            <h3 className='text-xs! font-semibold text-translatewp-muted!'>{__( 'Selected', 'translate' )}</h3>
                            <SelectedLanguageChips selected={targets} onChange={setTargets} slugs={setupSlugs( defaultLanguage, targets )} />
                            <p className='text-translatewp-muted! text-xs!' role="status">{activeLine}</p>
                            {estimateLine && <p className='text-translatewp-muted! text-xs!' data-setup-estimate>{estimateLine}</p>}
                        </div>
                    </div>
                    <div className='setup-language-checklist' data-language-checklist>
                        <LanguageChecklist selected={targets} onChange={setTargets} excludeLocales={[ defaultLanguage ]} compact />
                    </div>
                </div>
            </div>
        </div>
    );
}
