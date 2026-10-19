/**
 * Whether the signed-in user administers the plugin. False for a user who
 * manages translations by grant: they get the translation settings, the
 * Lexicon and the switcher settings, and nothing else. The server enforces
 * this; the app only leaves out what such a user cannot open.
 */
export const canAdminister = (): boolean => window.TranslateWP?.context?.can_administer !== false;

/** What the signed-in user may do on the site: each switches on its own edit-mode rows. */
export type ManagerCan = { edit: boolean; retranslate: boolean; settings: boolean; lexicon: boolean };

const NOTHING: ManagerCan = { edit: false, retranslate: false, settings: false, lexicon: false };

/**
 * The capabilities the page data carries; a server that predates them sends
 * only canManage, which then stands for all of them.
 */
export function managerCan(): ManagerCan
{
    const data = window.TranslateWP;
    if (data?.can) return { ...NOTHING, ...data.can };

    return data?.canManage === true ? { edit: true, retranslate: true, settings: true, lexicon: true } : NOTHING;
}

/** Edit mode opens for editing, retranslating or the switcher settings. */
export const opensEditMode = (can: ManagerCan = managerCan()): boolean => can.edit || can.retranslate || can.settings;
