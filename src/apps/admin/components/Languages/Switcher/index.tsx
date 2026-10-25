import { __ } from '@wordpress/i18n';
import { FormToggle, TextControl, ToggleControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';

import { useEffect, useId, useLayoutEffect, useRef, useState } from '@wordpress/element';
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

type MetricKey = 'offset' | 'icon_size' | 'padding';

const BACKGROUND_OPTIONS: OptionCard<'solid' | 'transparent' | 'glass'>[] = [
    { value: 'solid', label: __('Solid', 'translate'), description: __('Painted in the background colour.', 'translate') },
    { value: 'transparent', label: __('Transparent', 'translate'), description: __('No fill, a thin outline. For photos and videos.', 'translate') },
    { value: 'glass', label: __('Matte glass', 'translate'), description: __('Frosted and see-through, over anything.', 'translate') },
];

const DEFAULT_TEXT_COLOR = '#393535';
const TRANSPARENT_TEXT_COLOR = '#FFFFFF';
const NATIVE_REASON = __('The native dropdown always shows the language name.', 'translate');

const DESKTOP_QUERY = '(min-width: 1024px)';

// A control reached with the keyboard scrolls into view below the sticky preview.
const CLEAR_OF_PREVIEW = '[@media(min-height:640px)]:[&_:is(a,button,input,select,textarea,[tabindex])]:scroll-mt-64';

const useDesktop = (): boolean =>
{
    const supported = typeof window !== 'undefined' && typeof window.matchMedia === 'function';
    const [desktop, setDesktop] = useState(() => !supported || window.matchMedia(DESKTOP_QUERY).matches);

    useEffect(() =>
    {
        if (!supported) {
            return undefined;
        }
        const query = window.matchMedia(DESKTOP_QUERY);
        const update = () => setDesktop(query.matches);
        update();
        query.addEventListener('change', update);
        return () => query.removeEventListener('change', update);
    }, [supported]);

    return desktop;
};

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

    // A size field the browser has emptied keeps its own blank draft while the
    // stored value stands; leaving the field remounts it on the stored value.
    const [metricKeys, setMetricKeys] = useState<Record<MetricKey, number>>({ offset: 0, icon_size: 0, padding: 0 });

    // While the loading-border picker is open the preview shows the loading ring,
    // presentationally only: no translation starts and no loading flag is saved.
    const [loadingPreview, setLoadingPreview] = useState(false);

    // The translating message joins whichever desktop column is shorter once
    // everything else is in place, measured once so no box moves while in use.
    const desktop = useDesktop();
    const leftColumn = useRef<HTMLDivElement>(null);
    const rightColumn = useRef<HTMLDivElement>(null);
    const [translatingMessageSide, setTranslatingMessageSide] = useState<'left' | 'right' | null>(null);

    useLayoutEffect(() =>
    {
        if (!desktop || translatingMessageSide !== null) {
            return;
        }
        const height = (column: HTMLElement | null) => column?.getBoundingClientRect().height ?? 0;
        setTranslatingMessageSide(height(rightColumn.current) < height(leftColumn.current) ? 'right' : 'left');
    }, [desktop, translatingMessageSide]);

    const snapBack = (key: MetricKey) => (event: { target: { value: string } }) =>
    {
        if (event.target.value === '' || !Number.isFinite(Number(event.target.value))) {
            setMetricKeys(previous => ({ ...previous, [key]: previous[key] + 1 }));
        }
    };

    const updateMetric = (key: MetricKey, value: string | undefined) =>
    {
        if (value === undefined || value === '') {
            return;
        }

        const parsed = Number(value);

        if (!Number.isFinite(parsed)) {
            return;
        }

        updateSetting(key, parsed < 0 ? 0 : Math.trunc(parsed));
    };

    const whereItShows = (
        <div data-switcher-section="where" className="flex min-w-0 flex-col gap-6">
            <div className="rounded-lg border border-translatewp-border p-5">
                <WhereItShows />
            </div>
            {settings.inline_placement && (
            <div className="rounded-lg border border-translatewp-border p-5" data-anchor-fallback>
                <div className='flex flex-col gap-2'>
                    <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Pages without the placed spot', 'translate')}</h4>
                    <p className='text-sm text-translatewp-muted!'>{__('Your switcher is placed next to an element of your site. On a page that does not have that element, show this instead.', 'translate')}</p>
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
                </div>
            </div>
            )}
        </div>
    );

    const translatingMessage = (
        <div data-switcher-section="pending-message" className="rounded-lg border border-translatewp-border p-5" data-pending-message>
            <div className='flex flex-col gap-2'>
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Translating message', 'translate')}</h4>
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
            </div>
        </div>
    );

    const languageNames = (
        <div data-switcher-section="language-names" className="rounded-lg border border-translatewp-border p-5" data-switcher-language-names>
            <div className='flex flex-col gap-2'>
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Language names', 'translate')}</h4>
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
            </div>
        </div>
    );

    const colors = (
        <div data-switcher-section="colors" className="rounded-lg border border-translatewp-border p-5">
            <div className='flex flex-col gap-4'>
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Colors', 'translate')}</h4>
                {settings.background_style === 'transparent' && (
                    <p className='text-sm text-translatewp-muted! m-0' data-background-color-note>{__('With Transparent, the background colour is used for the open language list, which stays frosted so it reads over the page.', 'translate')}</p>
                )}

                <div className="grid w-full min-w-0 grid-cols-2 gap-4 [&>*]:min-w-0" data-primary-colors>
                    <ColorPicker label={__('Text color', 'translate')} value={settings.text_color} onChange={(value) => updateSetting('text_color', value)} />
                    <ColorPicker label={__('Background color', 'translate')} value={settings.background_color} onChange={(value) => updateSetting('background_color', value)} />
                </div>
                <Unavailable when={native} reason={__('The native dropdown\'s list is drawn by the browser, which chooses its own highlight.', 'translate')}>
                    <div className="grid w-full min-w-0 grid-cols-2 gap-4 [&>*]:min-w-0" data-highlight-colors>
                        <ColorPicker label={__('Highlight text color', 'translate')} value={settings.highlight_text} onChange={(value) => updateSetting('highlight_text', value)} />
                        <ColorPicker label={__('Highlight background', 'translate')} value={settings.highlight_bg} onChange={(value) => updateSetting('highlight_bg', value)} />
                    </div>
                </Unavailable>
                <div className="grid w-full min-w-0 grid-cols-2 gap-4 [&>*]:min-w-0" data-border-colors>
                    <ColorPicker label={__('Border color', 'translate')} value={settings.border_color} onChange={(value) => updateSetting('border_color', value)} />
                    <ColorPicker label={__('Loading border color', 'translate')} value={settings.loading_border_color} onChange={(value) => updateSetting('loading_border_color', value)} onOpenChange={setLoadingPreview} />
                </div>
            </div>
        </div>
    );

    const preview = (
        <div data-switcher-section="preview" className="top-[var(--wp-admin--admin-bar--height,32px)] z-10 [@media(min-height:640px)]:sticky" data-switcher-preview-column>
            <div className="rounded-lg border border-translatewp-border bg-white/45 p-5 backdrop-blur-lg backdrop-saturate-150 [&_[data-twp-switcher-preview]]:bg-translatewp-neutral/5! [&_[data-twp-switcher-preview]]:border-translatewp-border/60! [&_[data-twp-switcher-preview]]:justify-center">
                <SwitcherPreview previewLoading={loadingPreview} />
            </div>
        </div>
    );

    const switcherType = (
        <div data-switcher-section="type" className="rounded-lg border border-translatewp-border p-5">
            <div className="flex min-w-0 flex-col gap-2">
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Switcher type', 'translate')}</h4>
                <SwitcherTypeControl value={renderedSwitcherType(settings.type)} onChange={(type) => updateSetting('type', type)} />
            </div>
        </div>
    );

    const showSelectedLanguage = (
        <div data-switcher-section="show-selected" className="rounded-lg border border-translatewp-border p-5">
            <div className="flex flex-col gap-2">
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Show selected language', 'translate')}</h4>
                <p className='text-sm text-translatewp-muted!'>{__('What the closed switcher shows, so visitors know it is a language switcher and which language is active.', 'translate')}</p>
                <Unavailable when={native} reason={NATIVE_REASON}>
                    <ShowSelectedLanguageControl value={settings.trigger} onChange={(trigger) => updateSetting('trigger', trigger)} />
                </Unavailable>
            </div>
        </div>
    );

    const flagIconStyle = (
        <div data-switcher-section="flag-style" className="rounded-lg border border-translatewp-border p-5">
            <div className='flex flex-col gap-4'>
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Flag icon style', 'translate')}</h4>
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
            </div>
        </div>
    );

    const background = (
        <div data-switcher-section="background" className="rounded-lg border border-translatewp-border p-5" data-switcher-background>
            <div className='flex flex-col gap-2'>
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Background', 'translate')}</h4>
                <p className='text-sm text-translatewp-muted!'>{__('How the switcher sits on your page. Transparent and Matte glass let a photo or video show through; the preview shows them on a busy background.', 'translate')}</p>
                <OptionCards
                    name="background-style"
                    label={__('Background', 'translate')}
                    value={settings.background_style ?? 'solid'}
                    onChange={chooseBackground}
                    options={BACKGROUND_OPTIONS}
                />
            </div>
        </div>
    );

    const sizes = (
        <div data-switcher-section="sizes" className="rounded-lg border border-translatewp-border p-5" data-switcher-sizes>
            <div className='flex flex-col gap-4'>
                <h4 className='text-sm! font-bold! text-translatewp-neutral m-0!'>{__('Sizes', 'translate')}</h4>
                {native && <p className='m-0 text-sm text-translatewp-muted! italic' data-unavailable-reason>{__('The native dropdown is sized by the browser; only the offset applies.', 'translate')}</p>}
                {sideTab && <p className='m-0 text-sm text-translatewp-muted! italic' data-unavailable-reason>{__('The side tab sits on the screen edge, so the offset does not apply.', 'translate')}</p>}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div className={native ? 'pointer-events-none opacity-50' : ''} aria-disabled={native || undefined} data-size-icon>
                <NumberControl
                    key={`icon_size-${metricKeys.icon_size}`}
                    onBlur={snapBack('icon_size')}
                    __next40pxDefaultSize
                    label={__('Switcher icon size', 'translate')}
                    help={__('Height of the flag or icon on the closed switcher. The text scales with it.', 'translate')}
                    value={settings.icon_size}
                    onChange={(value) => updateMetric('icon_size', value)}
                    min={0}
                    max={500}
                    suffix={<span className='text-sm text-translatewp-muted! pe-2'>px</span>}
                    className='max-w-40'
                />
                </div>
                <div className={native ? 'pointer-events-none opacity-50' : ''} aria-disabled={native || undefined} data-size-padding>
                <NumberControl
                    key={`padding-${metricKeys.padding}`}
                    onBlur={snapBack('padding')}
                    __next40pxDefaultSize
                    label={__('Switcher padding', 'translate')}
                    help={__('Space inside the switcher, around the flag and the name.', 'translate')}
                    value={settings.padding}
                    onChange={(value) => updateMetric('padding', value)}
                    min={0}
                    max={500}
                    suffix={<span className='text-sm text-translatewp-muted! pe-2'>px</span>}
                    className='max-w-40'
                />
                </div>
                <div className={sideTab ? 'pointer-events-none opacity-50' : ''} aria-disabled={sideTab || undefined} data-size-offset>
                <NumberControl
                    key={`offset-${metricKeys.offset}`}
                    onBlur={snapBack('offset')}
                    __next40pxDefaultSize
                    label={__('Switcher offset', 'translate')}
                    help={__('Distance from the browser edge before dragging, and on narrow screens.', 'translate')}
                    value={settings.offset}
                    onChange={(value) => updateMetric('offset', value)}
                    min={0}
                    max={500}
                    suffix={<span className='text-sm text-translatewp-muted! pe-2'>px</span>}
                    className='max-w-40'
                />
                </div>
                </div>
            </div>
        </div>
    );

    const support = <SupportTranslatewp />;
    const column = (side: 'left' | 'right') => (
        <div className="flex min-w-0 flex-col gap-6" ref={side === 'left' ? leftColumn : rightColumn} data-switcher-column={side}>
            {side === 'left' ? (
                <>
                    {whereItShows}
                    {flagIconStyle}
                    {background}
                    {colors}
                </>
            ) : (
                <>
                    {preview}
                    {switcherType}
                    {showSelectedLanguage}
                    {languageNames}
                    {sizes}
                </>
            )}
            {translatingMessageSide === side && translatingMessage}
            {side === 'right' && support}
        </div>
    );

    return (
        <div className='flex flex-col gap-8 min-w-0'>
            <div className='flex flex-col gap-2'>
                <h3 className='font-semibold text-lg text-translatewp-neutral!'>{__('Language switcher', 'translate')}</h3>
                <p className='text-sm text-translatewp-muted!'>{__('How the language switcher looks and where it shows. Every change shows in the preview first.', 'translate')}</p>
            </div>

            <ManagedByNetwork group="switcher_appearance">
            {desktop ? (
                <div className={`grid grid-cols-2 items-start gap-6 ${CLEAR_OF_PREVIEW}`} data-switcher-layout>
                    {column('left')}
                    {column('right')}
                </div>
            ) : (
                <div className={`flex min-w-0 flex-col gap-6 ${CLEAR_OF_PREVIEW}`} data-switcher-layout>
                    {whereItShows}
                    {preview}
                    {switcherType}
                    {showSelectedLanguage}
                    {flagIconStyle}
                    {background}
                    {colors}
                    {languageNames}
                    {sizes}
                    {translatingMessage}
                    {support}
                </div>
            )}
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
        <div data-switcher-section="support" className="rounded-lg border border-translatewp-border p-5" data-support-translatewp>
            <div className='flex flex-col gap-4'>
                <div className='flex items-center gap-2'>
                    <span id={nameId} className='text-sm font-bold text-translatewp-neutral'>{__('Support TranslateWP', 'translate')}</span>
                    {!orbital && <ProMarker />}
                </div>
                {orbital ? toggle : <ProLock feature={__('turning off the TranslateWP line', 'translate')}>{toggle}</ProLock>}
            </div>
        </div>
    );
};