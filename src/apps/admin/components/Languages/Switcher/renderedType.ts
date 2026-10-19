import type { SwitcherType } from '@/apps/admin/stores/settingsStore';
import { getProState, isProActive } from '@/shared/lib/pro/proState';

/**
 * The type the site renders: a stored Side tab is the dropdown while Pro is
 * not active, as on the public site. The stored value is kept for a renewal.
 */
export const renderedSwitcherType = (type: SwitcherType): SwitcherType =>
    type === 'side_tab' && !isProActive(getProState()) ? 'dropdown' : type;
