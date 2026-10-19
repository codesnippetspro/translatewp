export const isGroupLocked = (group: string): boolean =>
    window.TranslateWP?.context?.settings_scope?.[group] === true;

export const networkSettingsUrl = (): string => window.TranslateWP?.context?.network_settings_url ?? '';
