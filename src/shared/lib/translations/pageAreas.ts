export const PAGE_AREAS = ['comments', 'menus', 'widgets'] as const;

export type PageArea = typeof PAGE_AREAS[number];

const AREA_SELECTORS: Record<PageArea, string[]> = {
    comments: ['#comments', '.comments-area', '.comment-list', '.commentlist', '.wp-block-comments', '.wp-block-post-comments', '#respond', '.comment-respond', '.wp-block-post-comments-form'],
    menus: ['nav', '[role="navigation"]', '.wp-block-navigation', '.menu', '.nav-menu', '.main-navigation', '#site-navigation'],
    widgets: ['.widget', '.widget-area', '.wp-block-widget-area', '#secondary', '.sidebar', '.wp-block-legacy-widget'],
};

export const isPageArea = (value: unknown): value is PageArea =>
    typeof value === 'string' && (PAGE_AREAS as readonly string[]).includes(value);

/** One CSS selector matching every element of the given areas; empty when none applies. */
export const pageAreaSelector = (areas: readonly string[]): string =>
    areas.filter(isPageArea).flatMap(area => AREA_SELECTORS[area]).join(', ');
