import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { addAction, applyFilters, removeAction } from '@wordpress/hooks';
import type { ComponentType } from 'react';

import { ProSlotHost } from '@/shared/components/pro/ProSlotHost';
import type { ProPanelComponent } from '@/shared/host';
import { getProState } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

export const useProSlot = (name: string): ComponentType | null =>
{
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    // The addon registers its panel when its own script runs, which can be
    // after this hook's last render; a registration for this slot re-renders
    // so the panel replaces the gate without a reload.
    const [registrationTick, setRegistrationTick] = useState(0);
    useEffect(() =>
    {
        const hookName = `translatewp.slot.${name}`;
        const namespace = `translatewp/slot-watch/${name}`;
        addAction('hookAdded', namespace, (addedHook: string) =>
        {
            if (addedHook === hookName) setRegistrationTick(tick => tick + 1);
        });
        return () => { removeAction('hookAdded', namespace); };
    }, [name]);
    // registrationTick is read so the filter is re-applied after a late registration.
    void registrationTick;
    const panel = applyFilters(`translatewp.slot.${name}`, null) as ProPanelComponent | null;
    const { entitled } = getProState(connectStatus);

    return useMemo<ComponentType | null>(
        () => (entitled && panel ? () => <ProSlotHost panel={panel} /> : null),
        [entitled, panel],
    );
};
