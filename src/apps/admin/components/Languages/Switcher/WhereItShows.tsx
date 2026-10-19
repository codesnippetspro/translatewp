import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import type { ReactNode } from 'react';

import { ZONE_LABELS, type InlinePlacement } from '@/apps/translatewp/inlinePlacement';
import { EDIT_MODE_PARAM } from '@/apps/translatewp/editMode';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import type { FloatingSwitcherPosition, SideTabEdge } from '@/shared/lib/switcherPosition';
import { resolveSideTabPosition } from '@/apps/translatewp/sideTab';
import { renderedSwitcherType } from '@/apps/admin/components/Languages/Switcher/renderedType';
import { CopyCode } from '@/shared/components/copyCode';
import { ProMarker } from '@/shared/components/pro/ProMarker';
import { ProLock } from '@/shared/components/pro/ProLock';
import { getProState, isProActive } from '@/shared/lib/pro/proState';

const anchorLabel = (anchor: FloatingSwitcherPosition['anchor'] | undefined): string =>
{
    switch (anchor) {
        case 'top-left': return __('Top left', 'translate');
        case 'bottom-left': return __('Bottom left', 'translate');
        case 'bottom-right': return __('Bottom right', 'translate');
        default: return __('Top right', 'translate');
    }
};

const edgeLabel = (edge: SideTabEdge): string =>
{
    switch (edge) {
        case 'top': return __('top edge', 'translate');
        case 'left': return __('left edge', 'translate');
        case 'right': return __('right edge', 'translate');
        default: return __('bottom edge', 'translate');
    }
};

const inlineLabel = (placement: InlinePlacement | null | undefined): string =>
{
    if (!placement) return __('None', 'translate');
    if (placement.kind === 'zone') return ZONE_LABELS[placement.zone] ?? __('None', 'translate');
    switch (placement.position)
    {
        case 'left': return __('Left of an element you chose', 'translate');
        case 'right': return __('Right of an element you chose', 'translate');
        case 'before': case 'prepend': return __('Above an element you chose', 'translate');
        default: return __('Below an element you chose', 'translate');
    }
};

const editModeUrl = (): string =>
{
    const home = window.TranslateWP?.context?.home_url || '/';
    try {
        const url = new URL(home, window.location.origin);
        url.searchParams.set(EDIT_MODE_PARAM, '1');
        return url.toString();
    } catch {
        return `/?${EDIT_MODE_PARAM}=1`;
    }
};

const Row = ({ title, badge, children }: { title: string; badge?: ReactNode; children: ReactNode }) => (
    <div className="flex flex-col gap-1 border-b border-translatewp-border! py-3 last:border-b-0">
        <div className="flex items-center gap-2">
            <span className="text-sm font-bold text-translatewp-neutral!">{title}</span>
            {badge}
        </div>
        <div className="text-sm text-translatewp-muted!">{children}</div>
    </div>
);

/**
 * Free places the switcher as the floating switcher, the widget, the menu
 * item, the block, a button and the shortcode. The inline placement is Pro:
 * on Free it is one locked row, and an entitled site without the addon gets
 * the install prompt from the lock.
 */
export const WhereItShows = () =>
{
    const switcher = useAdminSettingsStore(state => state.settings['languages.switcher']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const proActive = isProActive(getProState());

    // Each type keeps its own place; the row names and resets the one in use.
    const sideTab = renderedSwitcherType(switcher.type) === 'side_tab';
    const saved = sideTab ? switcher.side_tab_position ?? null : switcher.floating_position ?? null;
    const place = sideTab ? edgeLabel(resolveSideTabPosition(switcher, 'desktop').edge) : anchorLabel(switcher.floating_position?.anchor);

    const resetPosition = () => setSetting('languages.switcher', previous => ({ ...previous, ...(sideTab ? { side_tab_position: null } : { floating_position: null }) }));

    const inlineLine = __('Anchor the switcher to any element of the page, on the side where it should sit, from edit mode on your site.', 'translate');

    return (
        <section data-twp-where-it-shows className="flex flex-col gap-2" aria-labelledby="twp-where-it-shows">
            <span id="twp-where-it-shows" className="text-sm font-bold text-translatewp-neutral">{__('Where it shows', 'translate')}</span>
            <p className="text-sm text-translatewp-muted! m-0">{__('The floating switcher shows on every page until you place the switcher inside the page.', 'translate')}</p>

            <div className="flex flex-col rounded-lg border border-translatewp-border! px-4">
                <Row title={__('Floating switcher', 'translate')}>
                    <div className="flex flex-wrap items-center gap-3">
                        <span>
                            {saved
                                /* translators: %s: a corner of the browser window, such as "Bottom left", or an edge, such as "left edge". */
                                ? sprintf(__('Position: %s, where you dragged it in edit mode on your site.', 'translate'), place)
                                /* translators: %s: a corner of the browser window, such as "Top right", or an edge, such as "bottom edge". */
                                : sprintf(__('Position: %s, the default. Drag it in edit mode on your site to move it.', 'translate'), place)}
                        </span>
                        {saved && (
                            <Button variant="secondary" size="small" onClick={resetPosition}>{__('Reset position', 'translate')}</Button>
                        )}
                    </div>
                </Row>

                <Row title={__('Widget', 'translate')}>
                    {__('Add the TranslateWP widget to a sidebar or footer in', 'translate')}{' '}
                    <a href="widgets.php">{__('Appearance > Widgets', 'translate')}</a>.
                </Row>

                <Row title={__('Menu item', 'translate')}>
                    {__('Add the Language Switcher item to a menu in', 'translate')}{' '}
                    <a href="nav-menus.php">{__('Appearance > Menus', 'translate')}</a>.
                </Row>

                <Row title={__('Block', 'translate')}>
                    {__('Add the Language Switcher block in the block editor.', 'translate')}
                </Row>

                <Row title={__('Button', 'translate')}>
                    {__('Select a Button block in the block editor and switch on Convert to language switcher.', 'translate')}
                </Row>

                <Row title={__('Shortcode', 'translate')}>
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                        {__('Paste', 'translate')}
                        <CopyCode value="[twp-languages]" />
                        {__('where the switcher should show.', 'translate')}
                    </span>
                </Row>

                {proActive && (
                    <Row title={__('Inline placement', 'translate')}>
                        <div className="flex flex-col gap-1">
                            {/* translators: %s: where the switcher is placed in the page, such as "Footer", or "None". */}
                            <span data-twp-inline-placement>{sprintf(__('Current: %s', 'translate'), inlineLabel(switcher.inline_placement))}</span>
                            <span>{inlineLine}</span>
                            <a data-twp-open-edit-mode href={editModeUrl()} target="_blank" rel="noreferrer">{__('Open the site in edit mode', 'translate')}</a>
                        </div>
                    </Row>
                )}

                {!proActive && (
                    <div data-twp-locked-placements className="py-3">
                        <div className="flex items-center gap-2 pb-2">
                            <span className="text-sm font-bold text-translatewp-neutral!">{__('Inline placement', 'translate')}</span>
                            <ProMarker />
                        </div>
                        <ProLock feature={__('Switcher placements', 'translate')}>
                            <p className="m-0 text-sm text-translatewp-muted!">{inlineLine}</p>
                        </ProLock>
                    </div>
                )}
            </div>
        </section>
    );
};
