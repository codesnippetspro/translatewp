// The admin has four pages, Dashboard (`translatewp`), Lexicon,
// SEO Tools and Settings. Sub-pages are registered as `translatewp-<route>`.

// Absolute wp-admin URL for a TranslateWP route, derived from the current
// admin.php location so it keeps the same origin and path without a localized
// base. The dashboard is registered as `translatewp`, sub-pages as
// `translatewp-<route>`.
export const buildAdminUrl = (nextRoute: string, nextPage?: string): string => {
    const url = new URL(window.location.href);
    const normalizedRoute = nextRoute.replace(/^\/+/, '');
    url.searchParams.set('page', normalizedRoute ? `translatewp-${normalizedRoute}` : 'translatewp');
    url.hash = nextPage ?? '';

    return url.toString();
};

export const currentHash = (): string => window.location.hash.replace(/^#/, '').trim().toLowerCase();

export const useNavigate = () => {
    const page = new URLSearchParams(window.location.search).get('page');
    const pageName = currentHash();
    const route = page ? page.startsWith('translatewp-') ? page.replace('translatewp-', '/') : '/' : '/';

    const navigate = (nextRoute: string, nextPage?: string) => {
        window.location.href = buildAdminUrl(nextRoute, nextPage);
    };

    return { route, pageName, navigate };
};
