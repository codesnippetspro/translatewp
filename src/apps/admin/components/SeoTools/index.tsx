import { ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useQuery } from '@tanstack/react-query';

import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProLock } from '@/shared/components/pro/ProLock';
import { ManagedByNetwork } from '@/shared/components/managedByNetwork';
import { useProSlot } from '@/apps/admin/hooks/useProSlot';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { getProState } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { PageBody, PageHeader } from '@/apps/admin/components/PageLayout';

const TOGGLE_CLASS_NAME = '[&_.components-flex]:gap-4! [&_.components-flex]:items-start! mb-0! text-sm!';

const ToggleLabel = ({ title, description }: { title: string; description: string }) => (
    <div className='flex flex-col gap-1'>
        <p className='font-bold text-sm! text-translatewp-neutral!'>{title}</p>
        <span className='text-sm text-translatewp-muted!'>{description}</span>
    </div>
);

const BasicSeo = () =>
{
    const seo = useAdminSettingsStore(state => state.settings['languages.seo']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);

    return (
        <section className="flex flex-col gap-6" aria-labelledby="twp-seo-basic">
            <div className="flex flex-col gap-2">
                <h3 id="twp-seo-basic" className="font-semibold text-lg text-translatewp-neutral! m-0">{__('Basic', 'translate')}</h3>
                <p className="text-sm text-translatewp-muted! m-0">
                    {__('Included in TranslateWP Free. Every page names its own language version as the canonical address, so search engines index each language once.', 'translate')}
                </p>
            </div>

            <ManagedByNetwork group="seo_technical">
                <ToggleControl
                    label={<ToggleLabel
                        title={__('Output hreflang tags', 'translate')}
                        description={__('Tell search engines which language version of each page to show to which searcher.', 'translate')}
                    />}
                    checked={seo.output_hreflang}
                    onChange={(value) => setSetting('languages.seo', previous => ({ ...previous, output_hreflang: value }))}
                    className={TOGGLE_CLASS_NAME}
                />
                {seo.output_hreflang && (
                    <ToggleControl
                        label={<ToggleLabel
                            title={__('Include x-default pointing to the default language', 'translate')}
                            description={__('Searchers whose language the site does not offer are sent to the default language.', 'translate')}
                        />}
                        checked={seo.include_x_default}
                        onChange={(value) => setSetting('languages.seo', previous => ({ ...previous, include_x_default: value }))}
                        className={TOGGLE_CLASS_NAME}
                    />
                )}
            </ManagedByNetwork>
        </section>
    );
};

const proSeoOptions = (): string[] => [
    __('Translated sitemaps', 'translate'),
    __('Translated titles and meta descriptions', 'translate'),
    __('Open Graph tags', 'translate'),
    __('Structured data', 'translate'),
    __('IndexNow', 'translate'),
    __('Translated RSS feeds and language headers', 'translate'),
    __('AI crawler controls', 'translate'),
    __('llms.txt', 'translate'),
    __('AI alt text', 'translate'),
];

// Each Pro option as the row it is with Pro, switched off and out of reach.
const ProSeoFeatures = () => (
    <div className="flex flex-col gap-4">
        {proSeoOptions().map(option => (
            <div key={option} data-twp-pro-seo-option>
                <ToggleControl
                    label={<p className='font-bold text-sm! text-translatewp-neutral! m-0'>{option}</p>}
                    checked={false}
                    disabled
                    onChange={() => undefined}
                    className={TOGGLE_CLASS_NAME}
                />
            </div>
        ))}
    </div>
);

// On Free the page opens with the upsell modal over the locked Pro part; the
// basic controls stay live once it is closed.
export const SeoTools = () =>
{
    const Panel = useProSlot('seo');
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    // The "update it" copy is only true when the addon is active and older
    // than this slot; otherwise the Pro prompt decides between install and upgrade.
    const proState = getProState(connectStatus);
    const addonOutdated = proState.entitled && proState.active;

    return (
        <div className="flex flex-col">
            <PageHeader
                title={__('SEO Tools', 'translate')}
                description={__('How search engines and answer engines see every language of your site.', 'translate')}
            />

            <PageBody>
                {Panel ? <Panel /> : (
                    <div className="flex flex-col gap-10">
                        <BasicSeo />

                        <section className="flex flex-col gap-4" aria-labelledby="twp-seo-pro">
                            <div className="flex items-center gap-3">
                                <h3 id="twp-seo-pro" className="font-semibold text-lg text-translatewp-neutral! m-0">{__('TranslateWP Pro', 'translate')}</h3>
                                <ProMarker />
                            </div>
                            {addonOutdated ? (
                                <p className="text-sm text-translatewp-muted! m-0">
                                    {__('Your TranslateWP Pro addon does not include SEO Tools yet. Update it from your Plugins screen when a new version is available.', 'translate')}
                                </p>
                            ) : (
                                <ProLock feature={__('SEO Tools', 'translate')} defaultOpen>
                                    <div className="flex flex-col gap-3 p-6 border border-translatewp-border rounded-lg">
                                        <p className="text-sm text-translatewp-muted! m-0">
                                            {__('Upgrade to TranslateWP Pro for the complete SEO suite in every language:', 'translate')}
                                        </p>
                                        <ProSeoFeatures />
                                    </div>
                                </ProLock>
                            )}
                        </section>
                    </div>
                )}
            </PageBody>
        </div>
    );
};
