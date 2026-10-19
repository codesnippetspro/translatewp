import { Button, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { buildAdminUrl } from '@/apps/admin/hooks/useNavigate';
import { DefaultLanguageControl } from '@/apps/admin/components/Languages/Setup/DefaultLanguageControl';
import { fetchIndexStatus, type IndexStatus } from '@/apps/admin/components/Translations/Indexing/extractionDriver';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import type { Language } from '@/shared/components/languageTable';
import { Card, Section } from '@/shared/components/adminSurface';
import { ManagedByNetwork } from '@/shared/components/managedByNetwork';
import { useTranslationReadiness } from '@/shared/hooks/useTranslationReadiness';

const nameOf = (locale: string) => getLanguageNameFromLocale(locale, true) || getLanguageNameFromLocale(locale) || locale;

// One card shape for every Dashboard block: the same border, padding, title
// row and gap, so the sections read as one surface.
export { Card, Section };

export const CardLink = ({ href, children }: { href: string; children: React.ReactNode }) => (
    <a href={href} className="text-sm text-translatewp-primary underline">{children}</a>
);

// A section of the Dashboard: an eyebrow, one line of context, then its cards.
/**
 * Dashboard shortcuts built from the same components and the same
 * store as their Settings tab. No setting has a second copy.
 */
export const LanguagesCard = () =>
{
    const languages = useAdminSettingsStore(state => state.settings['languages.list']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const autoTranslate = useAdminSettingsStore(state => state.settings['translations.settings'].auto_translate);
    const translationLanguages = languages.filter(language => !language.default);

    const toggle = (locale: string) => setSetting('languages.list', previous =>
        previous.map(language => language.locale === locale ? { ...language, active: !language.active } : language));

    return (
        <Card
            title={__('Languages', 'translate')}
            action={<CardLink href={buildAdminUrl('settings', 'languages')}>{__('Language settings', 'translate')}</CardLink>}
        >
            <DefaultLanguageControl compact />
            <div className="flex flex-col gap-2">
                <span className="text-sm font-bold text-translatewp-neutral!">{__('Translation languages', 'translate')}</span>
                {translationLanguages.length === 0 ? (
                    <p className="text-sm text-translatewp-muted! m-0">{__('No translation languages yet.', 'translate')}</p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {translationLanguages.map((language: Language) => (
                            <button
                                key={language.locale}
                                type="button"
                                aria-pressed={language.active}
                                onClick={() => toggle(language.locale)}
                                className={`rounded-sm border px-2.5 py-1 text-sm cursor-pointer ${language.active ? 'border-translatewp-primary bg-translatewp-selection text-translatewp-neutral' : 'border-translatewp-border bg-white text-translatewp-muted line-through'}`}
                                title={language.active ? __('Switch off', 'translate') : __('Switch on', 'translate')}
                            >
                                <span className="flex items-center gap-1.5">
                                    {getCountryFlagFromLocale(language.locale)}
                                    <span>{nameOf(language.locale)}</span>
                                </span>
                            </button>
                        ))}
                    </div>
                )}
                <div>
                    <Button variant="secondary" href={buildAdminUrl('settings', 'languages')}>{__('Add languages', 'translate')}</Button>
                </div>
            </div>
            <ManagedByNetwork group="visitor_language">
                <ToggleControl
                    label={__('Auto-detect browser language', 'translate')}
                    checked={autoTranslate}
                    onChange={(value) => setSetting('translations.settings', previous => ({ ...previous, auto_translate: value }))}
                    className='mb-0! text-sm!'
                />
            </ManagedByNetwork>
        </Card>
    );
};

const DAY_MS = 24 * 60 * 60 * 1000;

export const TranslationCard = () =>
{
    const settings = useAdminSettingsStore(state => state.settings['translations.settings']);
    const style = useAdminSettingsStore(state => state.settings['translations.style']);
    const context = useAdminSettingsStore(state => state.settings['translations.context']);
    const contextText = (context.text_context ?? '').trim();
    const hasContext = (context.text_context ?? '').trim() !== '' || (context.last_scan_time ?? 0) > 0;
    const scanDays = context.last_scan_time ? Math.max(0, Math.floor((Date.now() - context.last_scan_time * 1000) / DAY_MS)) : null;

    const scope = settings.translate_all_content
        ? __('All public content', 'translate')
        : __('Selected content', 'translate');
    const capitalise = (value: string) => value
        ? value.charAt(0).toUpperCase() + value.slice(1)
        : __('Not set', 'translate');
    const scanLine = scanDays === null
        ? null
        : scanDays === 0
            ? __('Scanned today', 'translate')
            : sprintf(
                /* translators: %d: days since the context scan */
                _n('Scanned %d day ago', 'Scanned %d days ago', scanDays, 'translate'),
                scanDays
            );
    const details = [
        { label: __('Content', 'translate'), value: scope },
        { label: __('Writing style', 'translate'), value: capitalise(String(style.type ?? '')) },
        { label: __('Tone', 'translate'), value: capitalise(String(style.tone ?? '')) },
    ];

    return (
        <Card
            title={__('Translation', 'translate')}
            action={<CardLink href={buildAdminUrl('settings', 'translation')}>{__('Translation settings', 'translate')}</CardLink>}
            className="min-w-0"
        >
            <dl className="m-0 flex flex-col divide-y divide-translatewp-border">
                {details.map(({ label, value }) => (
                    <div key={label} className="grid grid-cols-2 gap-3 py-2.5 first:pt-0">
                        <dt className="text-sm text-translatewp-muted">{label}</dt>
                        <dd className="m-0 min-w-0 text-right text-sm font-medium text-translatewp-neutral break-words">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
            <div className="mt-auto flex flex-col gap-2 rounded-sm bg-translatewp-selection p-4">
                <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <h4 className="m-0! text-sm! font-semibold text-translatewp-neutral!">
                        {__('Website context', 'translate')}
                    </h4>
                    {scanLine && <span className="text-xs text-translatewp-muted">{scanLine}</span>}
                </div>
                <p className="m-0! text-sm text-translatewp-muted! line-clamp-2 break-words">
                    {contextText || (hasContext
                        ? __('Your site has been read. Review its context in Translation settings.', 'translate')
                        : __('Describe your site to help translations match your content.', 'translate'))}
                </p>
                {!hasContext && (
                    <CardLink href={buildAdminUrl('settings', 'translation')}>
                        {__('Add website context', 'translate')}
                    </CardLink>
                )}
            </div>
        </Card>
    );
};

export const IndexingSummary = () =>
{
    const liveStatus = useTranslationReadiness();
    const [status, setStatus] = useState<IndexStatus | null>(null);
    useEffect(() => { if (liveStatus) setStatus(liveStatus); }, [liveStatus]);
    const [failed, setFailed] = useState(false);

    useEffect(() =>
    {
        let cancelled = false;
        fetchIndexStatus()
            .then(next => { if (!cancelled) setStatus(next); })
            .catch(() => { if (!cancelled) setFailed(true); });
        return () => { cancelled = true; };
    }, []);

    const rows = Object.entries(status?.languages ?? {});

    return (
        <Card
            title={__('Indexing', 'translate')}
            action={<CardLink href={buildAdminUrl('', 'indexing')}>{__('View pages', 'translate')}</CardLink>}
        >
            {failed && <p className="text-sm text-translatewp-muted! m-0">{__('Indexing status could not be loaded.', 'translate')}</p>}
            {!failed && status && rows.length === 0 && (
                <p className="text-sm text-translatewp-muted! m-0">{__('Add a translation language, then index your site.', 'translate')}</p>
            )}
            {rows.length > 0 && (
                <ul className="m-0 flex flex-col gap-1 p-0 list-none">
                    {rows.map(([locale, row]) => (
                        <li key={locale} className="flex items-center justify-between text-sm text-translatewp-neutral!">
                            <span className="flex items-center gap-1.5">
                                {getCountryFlagFromLocale(locale)}
                                <span>{nameOf(locale)}</span>
                            </span>
                            <span className="text-translatewp-muted!">
                                {sprintf(
                                    /* translators: 1: pages done, 2: pages total */
                                    __('%1$d of %2$d pages', 'translate'),
                                    row.ready ?? 0,
                                    row.readyTotal ?? row.total
                                )}
                                {row.dead > 0 ? ` · ${sprintf(
                                    /* translators: %d: failed pages */
                                    _n('%d failed', '%d failed', row.dead, 'translate'),
                                    row.dead
                                )}` : ''}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            <div>
                <CardLink href={buildAdminUrl('', 'indexing')}>{__('View indexing detail', 'translate')}</CardLink>
            </div>
        </Card>
    );
};
