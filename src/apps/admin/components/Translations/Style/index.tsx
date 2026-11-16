import { __ } from '@wordpress/i18n';
import { useAdminSettingsStore, type TranslationsStyle as TranslationsStyleType } from "@/apps/admin/stores/settingsStore";

import { Range } from "@/shared/components/form/range";
import { CustomOption } from "@/apps/admin/components/Translations/Style/CustomOption";
import { ManagedByNetwork } from "@/shared/components/managedByNetwork";
import { StyleCard } from "@/apps/admin/components/Translations/Style/StyleCard";

export { StyleCard };
import { AdvancedStyleControls } from "@/apps/admin/components/Translations/Style/AdvancedStyleControls";

export const TranslationsStyle = () =>
{
    const style = useAdminSettingsStore(state => state.settings['translations.style']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);

    const updateStyle = <K extends keyof TranslationsStyleType,>(key: K, value: TranslationsStyleType[K]) =>
    {
        setSetting('translations.style', previous =>
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

    return (
        <div className="flex flex-col gap-6 lg:col-span-2" data-translation-style>
            <ManagedByNetwork group="style_tone">
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <StyleCard title={__('Preset style', 'translate')}>
                    <CustomOption
                        feature={__('Custom style', 'translate')}
                        slot="translationStyle"
                        kind="style"
                        selected={style.type}
                        onChange={(value) => updateStyle('type', value as TranslationsStyleType['type'])}
                    />
                </StyleCard>

                <StyleCard title={__('Preset tone', 'translate')}>
                    <CustomOption
                        feature={__('Custom tone', 'translate')}
                        slot="translationTone"
                        kind="tone"
                        selected={style.tone}
                        onChange={(value) => updateStyle('tone', value)}
                    />
                </StyleCard>

                <StyleCard title={__('Precision vs fluency', 'translate')}>
                    <Range
                        label={__('Precision vs fluency', 'translate')}
                        beginningLabel={__('Precise (literal)', 'translate')}
                        endingLabel={__('Creative (fluid)', 'translate')}
                        min={0}
                        max={10}
                        step={1}
                        value={style.creativity}
                        onChange={(value) => updateStyle('creativity', value)}
                    />
                </StyleCard>

                <StyleCard title={__('Formality', 'translate')}>
                    <Range
                        label={__('Formality', 'translate')}
                        beginningLabel={__('Informal', 'translate')}
                        endingLabel={__('Formal', 'translate')}
                        min={0}
                        max={10}
                        step={1}
                        value={style.formality}
                        onChange={(value) => updateStyle('formality', value)}
                    />
                </StyleCard>
            </div>
            </ManagedByNetwork>
        </div>
    );
}

export const TranslationsAdvancedStyle = () =>
{
    const style = useAdminSettingsStore(state => state.settings['translations.style']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const updateStyle = <K extends keyof TranslationsStyleType,>(key: K, value: TranslationsStyleType[K]) =>
        setSetting('translations.style', previous => previous[key] === value ? previous : { ...previous, [key]: value });

    return (
        <div className="flex flex-col gap-6 lg:col-span-2" data-translation-advanced-style>
            <ManagedByNetwork group="style_tone">
                <AdvancedStyleControls style={style} updateStyle={updateStyle} stacked />
            </ManagedByNetwork>
        </div>
    );
};
