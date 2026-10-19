import { __ } from '@wordpress/i18n';
import { Palette } from 'lucide-react';

import { useEffect } from '@wordpress/element';
import { useQuery } from '@tanstack/react-query';

import { CustomOption } from '@/apps/admin/components/Translations/Style/CustomOption';
import { PresetChoice } from '@/apps/admin/components/Translations/Style/PresetChoice';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { getProState, isProActive } from '@/shared/lib/pro/proState';
import { takePendingPreset } from '@/shared/lib/pro/pendingPreset';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';


export const StyleToneStep = () =>
{
    const { style, tone, setStyle, setTone } = useSetupStore();
    // The wizard holds style and tone in its own store, so the Pro custom
    // panel (which edits the saved settings) has no place here: with Pro the
    // Orbital presets are simply offered; custom text lives in Settings.
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    const proActive = isProActive(getProState(connectStatus));

    useEffect(() =>
    {
        if (!proActive) return;
        const pending = takePendingPreset();
        if (pending?.kind === 'style') setStyle(pending.value);
        if (pending?.kind === 'tone') setTone(pending.value);
    }, [proActive, setStyle, setTone]);

    return (
        <div className="setup-style-tone flex flex-col gap-6">
            <div className="flex flex-col gap-2">
                <div className="flex items-center gap-2">
                    <Palette className="w-5.5 h-5.5 text-translatewp-primary!" />
                    <h2 className="font-semibold m-0">{__('Style and tone', 'translate')}</h2>
                </div>
                <p className="text-translatewp-muted! text-sm! m-0">
                    {__('How your translations read. Reading your site proposed the choice below; change it if another fits better.', 'translate')}
                </p>
            </div>

            <div className="flex flex-col gap-3">
                <h3 className="text-sm! font-semibold m-0">{__('Style', 'translate')}</h3>
                {proActive
                    ? <PresetChoice kind="style" selected={style} onChange={setStyle} />
                    : <CustomOption feature={__('Custom style', 'translate')} slot="translationStyle" kind="style" selected={style} onChange={setStyle} />}
            </div>

            <div className="flex flex-col gap-3">
                <h3 className="text-sm! font-semibold m-0">{__('Tone', 'translate')}</h3>
                {proActive
                    ? <PresetChoice kind="tone" selected={tone} onChange={setTone} />
                    : <CustomOption feature={__('Custom tone', 'translate')} slot="translationTone" kind="tone" selected={tone} onChange={setTone} />}
            </div>
        </div>
    );
};
