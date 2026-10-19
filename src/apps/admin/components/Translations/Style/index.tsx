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
                <StyleCard title="Preset style">
                    <CustomOption
                        feature="Custom style"
                        slot="translationStyle"
                        kind="style"
                        selected={style.type}
                        onChange={(value) => updateStyle('type', value as TranslationsStyleType['type'])}
                    />
                </StyleCard>

                <StyleCard title="Preset tone">
                    <CustomOption
                        feature="Custom tone"
                        slot="translationTone"
                        kind="tone"
                        selected={style.tone}
                        onChange={(value) => updateStyle('tone', value)}
                    />
                </StyleCard>

                <StyleCard title="Precision vs fluency">
                    <Range
                        label="Precision vs fluency"
                        beginningLabel="Precise (literal)"
                        endingLabel="Creative (fluid)"
                        min={0}
                        max={10}
                        step={1}
                        value={style.creativity}
                        onChange={(value) => updateStyle('creativity', value)}
                    />
                </StyleCard>

                <StyleCard title="Formality">
                    <Range
                        label="Formality"
                        beginningLabel="Informal"
                        endingLabel="Formal"
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

// Audience, length and cultural adaptation stacked in one column, beside the site context.
export const TranslationsAdvancedStyle = () =>
{
    const style = useAdminSettingsStore(state => state.settings['translations.style']);
    const setSetting = useAdminSettingsStore(state => state.setSetting);
    const updateStyle = <K extends keyof TranslationsStyleType,>(key: K, value: TranslationsStyleType[K]) =>
        setSetting('translations.style', previous => previous[key] === value ? previous : { ...previous, [key]: value });

    return (
        <div className="flex flex-col gap-6" data-translation-advanced-style>
            <ManagedByNetwork group="style_tone">
                <AdvancedStyleControls style={style} updateStyle={updateStyle} stacked />
            </ManagedByNetwork>
        </div>
    );
};
