import { ProMarker } from "@/shared/components/pro/ProMarker";
import { rememberPendingPreset } from "@/shared/lib/pro/pendingPreset";
import { ProLock } from "@/shared/components/pro/ProLock";
import { useProSlot } from "@/apps/admin/hooks/useProSlot";
import { __ } from "@wordpress/i18n";
import { CHIP_CLASS, PresetChoice } from "@/apps/admin/components/Translations/Style/PresetChoice";
import { PRESET_OPTIONS, type PresetKind } from "@/apps/admin/components/Translations/Style/presetExamples";

interface CustomOptionProps
{
    feature: string;
    slot: 'translationStyle' | 'translationTone';
    kind: PresetKind;
    selected: string;
    onChange: (value: string) => void;
}

// The Pro custom style/tone panel renders the shared preset row plus its own
// "Custom" option, so when it is available it replaces the free control
// entirely (rendered through the same slot host as every other Pro panel). The
// free fallback keeps the preset row and shows a locked "Custom" choice.
export const CustomOption = ({ feature, slot, kind, selected, onChange }: CustomOptionProps) =>
{
    const Panel = useProSlot(slot);
    if (Panel) {
        return <Panel />;
    }

    return (
        <div className="flex flex-col gap-3">
            <PresetChoice kind={kind} selected={selected} onChange={onChange} />
            <div className="flex flex-wrap items-center gap-2" data-preset-pro-row={kind} data-custom-locked={kind}>
                {PRESET_OPTIONS[kind].filter(option => option.pro).map(option => (
                    <ProLock key={option.value} feature={option.label} className="w-fit" onProReady={() => rememberPendingPreset({ kind, value: option.value })}>
                        <span className={CHIP_CLASS} data-preset-locked={option.value}>{option.label}</span>
                    </ProLock>
                ))}
                <ProLock feature={feature} className="w-fit">
                    <span className={CHIP_CLASS}>{__('Custom', 'translate')}</span>
                </ProLock>
                <ProMarker />
            </div>
        </div>
    );
};
