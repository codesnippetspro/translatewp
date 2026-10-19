export interface NetworkSubsite {
    id: number;
    name: string;
    url: string;
    host: string;
    siteId: string;
    connected: boolean;
    setupEnded: boolean;
    languages: string[];
    dashboardUrl: string;
    seoUrl: string;
    lexiconUrl: string;
}

export interface NetworkConnectOutcome {
    host: string;
    name: string;
    ok: boolean;
    reconnected: boolean;
    reason: string;
    retry: boolean;
}

export type ScopeState = 'locked' | 'default' | 'local';

export interface NetworkScopeField {
    key: string;
    label: string;
    // role_map: ticked roles, which the server stores as every role true or false.
    type: 'boolean' | 'select' | 'color' | 'number' | 'range' | 'text' | 'roles' | 'role_map';
    help?: string;
    placeholder?: string;
    default: unknown;
    options: Record<string, string> | never[];
    value: unknown;
    pro?: boolean;
    /** Choices of a Free select that only Pro unlocks. */
    pro_values?: string[];
}

export interface NetworkScopeGroup {
    id: string;
    label: string;
    tier: 'network_only' | 'network_delegable' | 'network_default';
    switchable: boolean;
    state: ScopeState;
    exceptions: Record<string, ScopeState>;
    fields: NetworkScopeField[];
}

export interface NetworkScopeData {
    status: string;
    subsites: Array<{ id: number; name: string }>;
    groups: NetworkScopeGroup[];
}

export interface NetworkAdminData {
    connection: {
        connected: boolean;
        accountLabel: string;
        pro: ProPanelState;
        outcome: NetworkConnectOutcome[];
    };
    subsites: NetworkSubsite[];
    scope: NetworkScopeData;
    actions: {
        url: string;
        connect: string;
        scope: string;
        nonces: { connect: string; scope: string };
    };
    upgradeUrl: string;
}
import type { ProPanelState } from '@/shared/api/connect';
