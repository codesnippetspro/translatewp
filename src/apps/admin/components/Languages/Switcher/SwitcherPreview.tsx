import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import type { LanguageItem, PublicSwitcherSettings } from '@/apps/translatewp/types';
import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { renderedSwitcherType } from '@/apps/admin/components/Languages/Switcher/renderedType';

const BUSY_BACKGROUND = [
    'radial-gradient(circle at 18% 30%, rgba(250, 204, 21, 0.85) 0 14%, transparent 15%)',
    'radial-gradient(circle at 78% 70%, rgba(236, 72, 153, 0.8) 0 18%, transparent 19%)',
    'repeating-linear-gradient(45deg, rgba(255, 255, 255, 0.14) 0 8px, transparent 8px 16px)',
    'linear-gradient(110deg, #f1f5f9 0%, #cbd5e1 38%, #1e3a8a 62%, #0f172a 100%)',
].join(', ');

const FALLBACK_LANGUAGES: LanguageItem[] = [{ locale: 'en_US', slug: 'en', active: true, default: true }];

/**
 * The front-end switcher drawn with the current, unsaved settings, so a type
 * or colour change shows before it is saved. Choosing a language here does
 * nothing; it opens and closes like the real one.
 */
export const SwitcherPreview = ({ previewLoading = false }: { previewLoading?: boolean } = {}) =>
{
    const stored = useAdminSettingsStore(state => state.settings['languages.switcher']);
    const switcher = { ...stored, type: renderedSwitcherType(stored.type) };
    const list = useAdminSettingsStore(state => state.settings['languages.list']);
    const [isOpen, setIsOpen] = useState(false);

    const active = list.filter(language => language.active);
    const languages = active.length ? active : FALLBACK_LANGUAGES;
    const selectedLocale = languages.find(language => language.default)?.locale ?? languages[0].locale;
    // A see-through switcher is judged over a photo, so it is previewed over
    // a busy background, light on one side and dark on the other.
    const busy = switcher.background_style === 'transparent' || switcher.background_style === 'glass';

    return (
        <div className="flex flex-col gap-2">
            <span className="text-sm font-bold text-translatewp-neutral">{__('Preview', 'translate')}</span>
            <div
                data-twp-switcher-preview
                data-busy-background={busy ? 'true' : undefined}
                className="relative isolate flex min-h-24 items-start rounded-lg border border-translatewp-border! bg-translatewp-selection p-4"
                style={busy ? { backgroundImage: BUSY_BACKGROUND, backgroundSize: 'cover' } : undefined}
            >
                <SwitcherView
                    languages={languages}
                    switcher={switcher as PublicSwitcherSettings}
                    selectedLocale={selectedLocale}
                    isOpen={isOpen}
                    notice={null}
                    previewLoading={previewLoading}
                    variant={switcher.type === 'side_tab' ? 'preview' : 'inline'}
                    onOpenChange={setIsOpen}
                    onLanguageSelect={() => setIsOpen(false)}
                    onDismissNotice={() => {}}
                />
            </div>
        </div>
    );
};
