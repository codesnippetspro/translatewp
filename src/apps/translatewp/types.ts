import type { FloatingSwitcherPosition, SideTabPosition } from '@/shared/lib/switcherPosition';

export type LanguageItem = {
    locale: string;
    slug: string;
    active: boolean;
    default: boolean;
    switcher_label?: string;
};

export type SwitcherTrigger = 'name_flag' | 'flag' | 'icon';

export type PublicSwitcherSettings = {
	floating_position?: FloatingSwitcherPosition;
	/** Where the owner placed the floating switcher on a phone-width screen; the bottom corner until then. */
	floating_position_mobile?: FloatingSwitcherPosition;
	side_tab_position?: SideTabPosition;
	side_tab_position_mobile?: SideTabPosition;
    // A Pro site-wide inline placement.
    inline_placement?: import('@/apps/translatewp/inlinePlacement').InlinePlacement | null;
    anchor_fallback?: 'floating' | 'none';
    // The site serves side_tab only while Pro unlocks it; otherwise the dropdown.
    type?: 'dropdown' | 'native_select' | 'side_tab';
    // The type the owner chose, before the plan decides whether the site may serve it.
    selected_type?: 'dropdown' | 'native_select' | 'side_tab';
    // What the closed switcher shows: the selected language's flag and name, its flag, or the TranslateWP icon.
    trigger?: SwitcherTrigger;
    show_flag?: boolean;
    native_names?: boolean;
    regional_names?: 'full' | 'short';
    pending_message?: string;
    hide_branding?: boolean;
    icon_style?: 'rectangle' | 'square' | 'circle';
    text_color?: string;
    background_color?: string;
    border_color?: string;
    background_style?: SwitcherBackgroundStyle;
    highlight_text?: string;
    highlight_bg?: string;
    offset?: number;
    icon_size?: number;
    padding?: number;
};

/** Solid paints the background colour; transparent shows the page through an outline; glass frosts it. */
export type SwitcherBackgroundStyle = 'solid' | 'transparent' | 'glass';

export type TranslationIntent = 'automatic' | 'user';
