import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { addFilter } from '@wordpress/hooks';
import type { ComponentType } from 'react';

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';
import { Drawer } from '@/shared/components/drawer';
import { Table, TableCell, TableHeaderRow, TableRow } from '@/shared/components/form/table';
import { ProBadge } from '@/shared/components/pro/ProBadge';
import { LanguageScopeSwitcher } from '@/shared/components/LanguageScopeSwitcher';
import { ProLock } from '@/shared/components/pro/ProLock';
import { PresetChoice } from '@/apps/admin/components/Translations/Style/PresetChoice';
import { ManagedByNetwork } from '@/shared/components/managedByNetwork';
import { confirmLanguageRetranslation } from '@/shared/lib/confirmLanguageRetranslation';
import { isGroupLocked } from '@/shared/lib/settingsScope';
import { getCountryFlagFromLocale, getLanguageNameFromLocale } from '@/shared/lib/languages';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

export const HOST_BRIDGE_VERSION = 3;

export type ProPanelComponent = ComponentType & { hostVersion?: number };

const registerSlot = (
    name: string,
    panel: ProPanelComponent,
    namespace = 'translatewp-pro',
): void =>
{
    addFilter(`translatewp.slot.${name}`, namespace, () => panel);
};

export interface HostBridge
{
    version: number;
    useAdminSettingsStore: typeof useAdminSettingsStore;
    useQuery: typeof useQuery;
    useMutation: typeof useMutation;
    useQueryClient: typeof useQueryClient;
    getConnectStatusQueryOptions: typeof getConnectStatusQueryOptions;
    Table: typeof Table;
    TableCell: typeof TableCell;
    TableHeaderRow: typeof TableHeaderRow;
    TableRow: typeof TableRow;
    Drawer: typeof Drawer;
    getCountryFlagFromLocale: typeof getCountryFlagFromLocale;
    getLanguageNameFromLocale: typeof getLanguageNameFromLocale;
    ProLock: typeof ProLock;
    ManagedByNetwork: typeof ManagedByNetwork;
    isGroupLocked: typeof isGroupLocked;
    ProBadge: typeof ProBadge;
    LanguageScopeSwitcher: typeof LanguageScopeSwitcher;
    PresetChoice: typeof PresetChoice;
    registerSlot: typeof registerSlot;
    confirmLanguageRetranslation: typeof confirmLanguageRetranslation;
}

export const host: HostBridge = {
    version: HOST_BRIDGE_VERSION,
    useAdminSettingsStore,
    useQuery,
    useMutation,
    useQueryClient,
    getConnectStatusQueryOptions,
    Table,
    TableCell,
    TableHeaderRow,
    TableRow,
    Drawer,
    getCountryFlagFromLocale,
    getLanguageNameFromLocale,
    ProLock,
    ManagedByNetwork,
    isGroupLocked,
    ProBadge,
    LanguageScopeSwitcher,
    PresetChoice,
    registerSlot,
    confirmLanguageRetranslation,
};

export const installHostBridge = (): void =>
{
    window.TranslateWP = Object.assign(
        window.TranslateWP ?? ({} as Window['TranslateWP']),
        { host },
    );
};
