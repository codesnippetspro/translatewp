import { __ } from '@wordpress/i18n';

import { Dashboard } from '@/apps/admin/components/Dashboard';
import { LanguagesIndexing } from '@/apps/admin/components/Translations/Indexing';
import { RetranslateEverything } from '@/apps/admin/components/Settings/RetranslateEverything';
import { ErrorBoundary } from '@/shared/components/ErrorBoundary';
import { usePageHash } from '@/apps/admin/hooks/usePageHash';
import { PageBody, PageHeader, NavigationPage } from '@/apps/admin/components/PageLayout';

export const DASHBOARD_PAGES = [
    { name: 'overview', title: __('Overview', 'translate'), content: <Dashboard />, inset: false },
    {
        name: 'indexing',
        title: __('Indexing', 'translate'),
        content: (
            <div className="flex flex-col gap-12">
                <ErrorBoundary
                    title={__('The Indexing page could not load.', 'translate')}
                    message={__('Reload the page to try again. If it keeps failing, update TranslateWP from your Plugins screen.', 'translate')}
                >
                    <LanguagesIndexing />
                </ErrorBoundary>
                <RetranslateEverything />
            </div>
        ),
    },
] as NavigationPage[];

export const DashboardPage = () =>
{
    const { activePageName } = usePageHash(DASHBOARD_PAGES.map(page => page.name), 'overview');
    const current = DASHBOARD_PAGES.find(page => page.name === activePageName);

    return (
        <div className="flex flex-col">
            {current?.inset === false
                ? <div>{current.content}</div>
                : <>
                    <PageHeader title={current?.title} />
                    <PageBody>{current?.content}</PageBody>
                </>}
        </div>
    );
};
