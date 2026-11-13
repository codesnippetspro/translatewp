import { __ } from '@wordpress/i18n';
import { FormToggle, TextControl, ToggleControl } from '@wordpress/components';

import { useId, useState } from '@wordpress/element';
import { NumberField, numberFieldBounds } from '@/shared/components/form/numberField';
import { ColorPicker } from '@/shared/components/form/colorPicker';
import { SwitcherTypeControl } from '@/apps/admin/components/Languages/Switcher/SwitcherTypeControl';
import { ShowSelectedLanguageControl } from '@/apps/admin/components/Languages/Switcher/ShowSelectedLanguageControl';
import { OptionCards, type OptionCard } from '@/apps/admin/components/Languages/Switcher/OptionCards';
import { getCountryFlagFromLocale } from '@/shared/lib/languages';
import { SwitcherPreview } from '@/apps/admin/components/Languages/Switcher/SwitcherPreview';
import { WhereItShows } from '@/apps/admin/components/Languages/Switcher/WhereItShows';
import { Unavailable } from '@/apps/admin/components/Languages/Switcher/Unavailable';
import { useAdminSettingsStore, type SwitcherSettings } from '@/apps/admin/stores/settingsStore';
import { ManagedByNetwork } from '@/shared/components/managedByNetwork';
import { ProLock } from '@/shared/components/pro/ProLock';
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { getProState } from '@/shared/lib/pro/proState';
import { renderedSwitcherType } from '@/apps/admin/components/Languages/Switcher/renderedType';

const PENDING_MESSAGE_MAX_LENGTH = 200;

const BACKGROUND_OPTIONS: OptionCard<'solid' | 'transparent' | 'glass'>[] = [
    { value: 'solid', label: __('Solid', 'translate'), description: __('Painted in the background colour.', 'translate') },
    { value: 'transparent', label: __('Transparent', 'translate'), description: __('No fill, a thin outline. For photos and videos.', 'translate') },
    { value: 'glass', label: __('Matte glass', 'translate'), description: __('Frosted and see-through, over anything.', 'translate') },
];

const DEFAULT_TEXT_COLOR = '#393535';
const TRANSPARENT_TEXT_COLOR = '#FFFFFF';
const NATIVE_REASON = __('The native dropdown always shows the language name.', 'translate');

// A control reached with the keyboard scrolls into view below the sticky preview.
const CLEAR_OF_PREVIEW = '[@media(min-height:640px)]:[&_:is(a,button,input,select,textarea,[tabindex])]:scroll-mt-64';
const SECTION_CLASS = '@container flex min-w-0 flex-col gap-4 border-0 border-t border-solid border-translatewp-border py-8 [&>h3+p]:-mt-3';
const FIRST_SECTION_CLASS = `${SECTION_CLASS} border-t-0 pt-0`;
const TWO_COLUMN_GRID_CLASS = 'grid w-full min-w-0 grid-cols-1 gap-4 @min-[380px]:grid-cols-2 [&>*]:min-w-0';

const FLAG_EXAMPLES = ['en-us', 'de', 'ja'];
const flagExamples = (iconStyle: 'rectangle' | 'square' | 'circle') => <>{FLAG_EXAMPLES.map(locale => <span key={locale}>{getCountryFlagFromLocale(locale, { iconStyle })}</span>)}</>;

const FLAG_STYLE_OPTIONS: OptionCard<'rectangle' | 'square' | 'circle'>[] = [
    { value: 'rectangle', label: __('Rectangle', 'translate'), description: __('The flag as it is.', 'translate'), example: flagExamples('rectangle') },
    { value: 'square', label: __('Square', 'translate'), description: __('Cropped to a square.', 'translate'), example: flagExamples('square') },
    { value: 'circle', label: __('Circle', 'translate'), description: __('Cropped to a circle.', 'translate'), example: flagExamples('circle') },
];

export const LanguagesSwitcher = () =>
{
    const settings = useAdminSettingsStore(state => state.settings['languages.switcher']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    // The browser draws a native dropdown, so the settings for the drawn switcher do not apply to it.
    const native = settings.type === 'native_select';
    const sideTab = renderedSwitcherType(settings.type) === 'side_tab';

    const updateSetting = <K extends keyof SwitcherSettings,>(key: K, value: SwitcherSettings[K]) =>
    {
        setSetting('languages.switcher', previous =>
        {
            if (previous[key] === value) {
                return previous;
            }

            return {
                ...previous,
                [key]: value,
            };
        });
    };

    // Over a photo or video, white text reads best, so Transparent brings white
    // text with it while the text colour is still the default, and leaving
    // Transparent gives the default back. A colour the owner chose is kept.
    const chooseBackground = (style: 'solid' | 'transparent' | 'glass') =>
    {
        setSetting('languages.switcher', previous =>
        {
            const text = previous.text_color.toUpperCase();
            const textColor = style === 'transparent' && text === DEFAULT_TEXT_COLOR
                ? TRANSPARENT_TEXT_COLOR
                : style !== 'transparent' && previous.background_style === 'transparent' && text === TRANSPARENT_TEXT_COLOR
                    ? DEFAULT_TEXT_COLOR
                    : previous.text_color;
            return { ...previous, background_style: style, text_color: textColor };
        });
    };

    // While the loading-border picker is open the preview shows the loading ring,
    // presentationally only: no translation starts and no loading flag is saved.
    const [loadingPreview, setLoadingPreview] = useState(false);

    const translatingMessage = (
        <section data-switcher-section="pending-message" className={SECTION_CLASS} data-pending-message aria-labelledby="twp-switcher-pending-message">
            <h3 id="twp-switcher-pending-message" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Translating message', 'translate')}</h3>
            <p className='text-sm text-translatewp-muted!'>{__('Shown to a visitor when a page takes longer than a few seconds to translate. Leave empty for the default line.', 'translate')}</p>
            <TextControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                label={__('Translating message', 'translate')}
                hideLabelFromVision
                value={settings.pending_message ?? ''}
                maxLength={PENDING_MESSAGE_MAX_LENGTH}
                placeholder={__('Translating this page for you, a few more seconds.', 'translate')}
                onChange={(value) => updateSetting('pending_message', value.slice(0, PENDING_MESSAGE_MAX_LENGTH))}
                className="[&_input]:border-translatewp-border! [&_input]:rounded-sm!"
            />
        </section>
    );

    const languageNames = (
        <section data-switcher-section="language-names" className={SECTION_CLASS} data-switcher-language-names aria-labelledby="twp-switcher-language-names">
            <h3 id="twp-switcher-language-names" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Language names', 'translate')}</h3>
            <p className='text-sm text-translatewp-muted!'>{__('How the switcher names a language that has a region.', 'translate')}</p>
            <ToggleControl label={
                <div className='flex flex-col gap-1'>
                    <p className='font-bold text-sm! text-translatewp-neutral!'>{__('Name each language in its own language', 'translate')}</p>
                    <span className='text-sm text-translatewp-muted!'>{__('Deutsch, Italiano, 日本語. Turn off to show the names in your site\'s base language.', 'translate')}</span>
                </div>
            } checked={settings.native_names !== false} onChange={(value) => updateSetting('native_names', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
                <OptionCards
                    name="regional-names"
                    label={__('Language names', 'translate')}
                    columns={2}
                    value={settings.regional_names}
                    onChange={(value) => updateSetting('regional_names', value)}
                    options={[
                        { value: 'full', label: __('Full name', 'translate'), description: __('American English, Österreichisches Deutsch', 'translate') },
                        { value: 'short', label: __('Short', 'translate'), description: __('English (US), Deutsch (AT). With flags on, the region flag and English.', 'translate') },
                    ]}
                />
        </section>
    );

    const colors = (
        <section data-switcher-section="colors" className={SECTION_CLASS} aria-labelledby="twp-switcher-colors">
            <h3 id="twp-switcher-colors" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Colors', 'translate')}</h3>
            {settings.background_style === 'transparent' && (
                <p className='text-sm text-translatewp-muted! m-0' data-background-color-note>{__('With Transparent, the background colour is used for the open language list, which stays frosted so it reads over the page.', 'translate')}</p>
            )}
            <div className={TWO_COLUMN_GRID_CLASS} data-primary-colors>
                <ColorPicker label={__('Text color', 'translate')} value={settings.text_color} onChange={(value) => updateSetting('text_color', value)} />
                <ColorPicker label={__('Background color', 'translate')} value={settings.background_color} onChange={(value) => updateSetting('background_color', value)} />
            </div>
            <Unavailable when={native} reason={__('The native dropdown\'s list is drawn by the browser, which chooses its own highlight.', 'translate')}>
                <div className={TWO_COLUMN_GRID_CLASS} data-highlight-colors>
                    <ColorPicker label={__('Highlight text color', 'translate')} value={settings.highlight_text} onChange={(value) => updateSetting('highlight_text', value)} />
                    <ColorPicker label={__('Highlight background', 'translate')} value={settings.highlight_bg} onChange={(value) => updateSetting('highlight_bg', value)} />
                </div>
            </Unavailable>
            <div className={TWO_COLUMN_GRID_CLASS} data-border-colors>
                <ColorPicker label={__('Border color', 'translate')} value={settings.border_color} onChange={(value) => updateSetting('border_color', value)} />
                <ColorPicker label={__('Loading border color', 'translate')} value={settings.loading_border_color} onChange={(value) => updateSetting('loading_border_color', value)} onOpenChange={setLoadingPreview} />
            </div>
        </section>
    );

    const preview = (
        <div data-switcher-section="preview" className="order-first mb-4 min-w-0 min-[1280px]:order-none min-[1280px]:col-start-2 min-[1280px]:row-start-1 min-[1280px]:mb-0 min-[1280px]:sticky min-[1280px]:top-[calc(var(--wp-admin--admin-bar--height,32px)+16px)]" data-switcher-preview-column>
            <div className="flex flex-col gap-4 rounded-lg border border-translatewp-border bg-white/45 p-5 backdrop-blur-lg backdrop-saturate-150 [&_[data-twp-switcher-preview]]:bg-translatewp-neutral/5! [&_[data-twp-switcher-preview]]:border-translatewp-border/60! [&_[data-twp-switcher-preview]]:justify-center">
                <SwitcherPreview previewLoading={loadingPreview} />
                <WhereItShows />
                {settings.inline_placement && (
                    <section className="@container flex flex-col gap-4 border-t border-translatewp-border pt-4" data-anchor-fallback aria-labelledby="twp-switcher-anchor-fallback">
                        <div className='flex flex-col gap-1'>
                            <h3 id="twp-switcher-anchor-fallback" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Pages without the placed spot', 'translate')}</h3>
                            <p className='m-0! text-sm text-translatewp-muted!'>{__('Your switcher is placed next to an element of your site. On a page that does not have that element, show this instead.', 'translate')}</p>
                        </div>
                        <OptionCards
                            name="anchor-fallback"
                            label={__('Pages without the placed spot', 'translate')}
                            columns={2}
                            value={settings.anchor_fallback ?? 'floating'}
                            onChange={(value) => updateSetting('anchor_fallback', value)}
                            options={[
                                { value: 'floating', label: __('Show the floating switcher', 'translate'), description: __('The corner widget, as on a site with no anchor.', 'translate') },
                                { value: 'none', label: __('Show no switcher', 'translate'), description: __('The page has no switcher; visitors use the language addresses.', 'translate') },
                            ]}
                        />
                    </section>
                )}
            </div>
        </div>
    );

    const switcherType = (
        <section data-switcher-section="type" className={FIRST_SECTION_CLASS} aria-labelledby="twp-switcher-type">
            <h3 id="twp-switcher-type" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Switcher type', 'translate')}</h3>
            <SwitcherTypeControl value={renderedSwitcherType(settings.type)} onChange={(type) => updateSetting('type', type)} />
        </section>
    );

    const showSelectedLanguage = (
        <section data-switcher-section="show-selected" className={SECTION_CLASS} aria-labelledby="twp-switcher-show-selected">
            <h3 id="twp-switcher-show-selected" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Show selected language', 'translate')}</h3>
            <p className='text-sm text-translatewp-muted!'>{__('What the closed switcher shows, so visitors know it is a language switcher and which language is active.', 'translate')}</p>
            <Unavailable when={native} reason={NATIVE_REASON}>
                <ShowSelectedLanguageControl value={settings.trigger} onChange={(trigger) => updateSetting('trigger', trigger)} />
            </Unavailable>
        </section>
    );

    const flagIconStyle = (
        <section data-switcher-section="flag-style" className={SECTION_CLASS} aria-labelledby="twp-switcher-flag-style">
            <h3 id="twp-switcher-flag-style" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Flag icon style', 'translate')}</h3>
            <ToggleControl label={
                <div className='flex flex-col gap-1'>
                    <p className='font-bold text-sm! text-translatewp-neutral!'>{settings.type === 'native_select' ? __('Show emoji flags', 'translate') : __('Show flag icon', 'translate')}</p>
                    <span className='text-sm text-translatewp-muted!'>{settings.type === 'native_select'
                        ? __('A browser select box can only show emoji flags, in front of each language name.', 'translate')
                        : __('Show the flag next to the language name.', 'translate')}</span>
                </div>
            } checked={settings.show_flag} onChange={(value) => updateSetting('show_flag', value as boolean)} className='[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!' />
                <div data-flag-style-choice>
                    <Unavailable
                        when={native || !settings.show_flag}
                        reason={native
                            ? __('The native dropdown shows emoji flags, which have no style to choose.', 'translate')
                            : __('Turn on the flag icon to choose its style.', 'translate')}
                    >
                        <OptionCards
                            name="flag-icon-style"
                            label={__('Flag icon style', 'translate')}
                            value={settings.icon_style}
                            onChange={(value) => updateSetting('icon_style', value)}
                            options={FLAG_STYLE_OPTIONS}
                        />
                    </Unavailable>
            </div>
        </section>
    );

    const background = (
        <section data-switcher-section="background" className={SECTION_CLASS} data-switcher-background aria-labelledby="twp-switcher-background">
            <h3 id="twp-switcher-background" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Background', 'translate')}</h3>
            <p className='text-sm text-translatewp-muted!'>{__('How the switcher sits on your page. Transparent and Matte glass let a photo or video show through; the preview shows them on a busy background.', 'translate')}</p>
            <OptionCards
                name="background-style"
                label={__('Background', 'translate')}
                value={settings.background_style ?? 'solid'}
                onChange={chooseBackground}
                options={BACKGROUND_OPTIONS}
            />
        </section>
    );

    const sizes = (
        <section data-switcher-section="sizes" className={SECTION_CLASS} data-switcher-sizes aria-labelledby="twp-switcher-sizes">
            <h3 id="twp-switcher-sizes" className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Sizes', 'translate')}</h3>
            {native && <p className='m-0 text-sm text-translatewp-muted! italic' data-unavailable-reason>{__('The browser draws the native dropdown. Font size and offset still apply.', 'translate')}</p>}
            {sideTab && <p className='m-0 text-sm text-translatewp-muted! italic' data-unavailable-reason>{__('The side tab sits on the screen edge, so the offset does not apply.', 'translate')}</p>}
            <div className="grid grid-cols-1 gap-4 @min-[380px]:grid-cols-2 @min-[500px]:grid-cols-3">
                <div data-size-font>
                <NumberField
                    label={__('Font size', 'translate')}
                    help={__('Size of the language names in the switcher.', 'translate')}
                    value={settings.font_size}
                    onChange={value => updateSetting('font_size', value)}
                    {...numberFieldBounds.fontSize}
                    unit="px"
                    disabled={false}
                    className="max-w-40"
                />
                </div>
                <div className={native ? 'pointer-events-none opacity-50' : ''} aria-disabled={native || undefined} data-size-icon>
                <NumberField
                    label={__('Switcher icon size', 'translate')}
                    help={__('Width of the flag or size of the icon on the closed switcher.', 'translate')}
                    value={settings.icon_size}
                    onChange={value => updateSetting('icon_size', value)}
                    {...numberFieldBounds.switcherMetric}
                    unit="px"
                    disabled={native}
                    className="max-w-40"
                />
                </div>
                <div className={native ? 'pointer-events-none opacity-50' : ''} aria-disabled={native || undefined} data-size-padding>
                <NumberField
                    label={__('Switcher padding', 'translate')}
                    help={__('Space inside the switcher, around the flag and the name.', 'translate')}
                    value={settings.padding}
                    onChange={value => updateSetting('padding', value)}
                    {...numberFieldBounds.switcherMetric}
                    unit="px"
                    disabled={native}
                    className="max-w-40"
                />
                </div>
                <div className={sideTab ? 'pointer-events-none opacity-50' : ''} aria-disabled={sideTab || undefined} data-size-offset>
                <NumberField
                    label={__('Switcher offset', 'translate')}
                    help={__('Distance from the browser edge before dragging, and on narrow screens.', 'translate')}
                    value={settings.offset}
                    onChange={value => updateSetting('offset', value)}
                    {...numberFieldBounds.switcherMetric}
                    unit="px"
                    disabled={sideTab}
                    className="max-w-40"
                />
                </div>
            </div>
        </section>
    );

    const support = <SupportTranslatewp />;

    return (
        <div className='flex flex-col gap-8 min-w-0'>
            <ManagedByNetwork group="switcher_appearance">
                <div className={`grid min-w-0 grid-cols-1 items-start min-[1280px]:grid-cols-[minmax(0,1fr)_300px] min-[1280px]:gap-x-10 ${CLEAR_OF_PREVIEW}`} data-switcher-layout>
                    <div className="min-w-0 min-[1280px]:col-start-1 min-[1280px]:row-start-1" data-switcher-column="settings">
                    {switcherType}
                    {showSelectedLanguage}
                    {flagIconStyle}
                    {languageNames}
                    {sizes}
                    {background}
                    {colors}
                    {translatingMessage}
                    {support}
                    </div>
                    {preview}
                </div>
            </ManagedByNetwork>
        </div>
    )
}

/**
 * The switcher's small "by TranslateWP" line. Stored as hide_branding; shown
 * here the way round a person thinks of it. Orbital sites start with it off,
 * Terrestrial sites always carry it.
 */
const SupportTranslatewp = () =>
{
    const switcher = useAdminSettingsStore(state => state.settings['languages.switcher']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    // The plan decides, as on the public switcher and in edit mode; the Pro addon is not needed.
    const orbital = getProState().entitled;
    const showing = !orbital || switcher.hide_branding === false;
    const nameId = useId();
    const sentenceId = useId();
    const toggle = (
        <div className='flex items-start gap-4'>
            <span className='flex' style={{ marginTop: 2 }}>
                <FormToggle
                    aria-labelledby={nameId}
                    aria-describedby={sentenceId}
                    checked={showing}
                    disabled={!orbital}
                    onChange={(event) => setSetting('languages.switcher', previous => ({ ...previous, hide_branding: !event.target.checked }))}
                />
            </span>
            <p id={sentenceId} className='m-0 text-sm text-translatewp-muted!'>{orbital
                ? __('Show a small \u201cby TranslateWP\u201d line under the languages. It is how most site owners find us, and it means a lot to the team.', 'translate')
                : __('Free sites show a small \u201cby TranslateWP\u201d line under the languages. Pro lets you turn it off.', 'translate')}</p>
        </div>
    );

    return (
        <section data-switcher-section="support" className={SECTION_CLASS} data-support-translatewp aria-labelledby={nameId}>
            <div className='flex items-center gap-2'>
                <h3 id={nameId} className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Support TranslateWP', 'translate')}</h3>
                {!orbital && <ProMarker />}
            </div>
            {orbital ? toggle : <ProLock feature={__('turning off the TranslateWP line', 'translate')}>{toggle}</ProLock>}
        </section>
    );
};
