import { __ } from '@wordpress/i18n';

import { Dashboard } from '@/apps/admin/components/Dashboard';
import { LanguagesIndexing } from '@/apps/admin/components/Translations/Indexing';
import { RetranslateEverything } from '@/apps/admin/components/Settings/RetranslateEverything';
import { SupportPanel } from '@/apps/admin/components/Transparency';
import { ErrorBoundary } from '@/shared/components/ErrorBoundary';
import { useTabHash } from '@/apps/admin/hooks/useTabHash';
import { PageTab, PageTabs } from '@/apps/admin/components/PageLayout';

// The Dashboard carries what the wizard sets plus status and the feedback
// form (Overview), the Indexing tab with "Retranslate everything" beside
// the per-page action, and the Support tab with feedback, the support report and tickets.
export const DASHBOARD_TABS = [
    { name: 'overview', title: __('Overview', 'translate'), content: <Dashboard />, inset: false },
    {
        name: 'indexing',
        title: __('Indexing', 'translate'),
        content: (
            <div className="flex flex-col gap-12">
                <ErrorBoundary
                    title={__('The Indexing tab could not load.', 'translate')}
                    message={__('Reload the page to try again. If it keeps failing, update TranslateWP from your Plugins screen.', 'translate')}
                >
                    <LanguagesIndexing />
                </ErrorBoundary>
                <RetranslateEverything />
            </div>
        ),
    },
    { name: 'support', title: __('Support', 'translate'), content: <SupportPanel /> },
] as PageTab[];

export const DashboardPage = () =>
{
    const { activeTabName, onSelectTab } = useTabHash(DASHBOARD_TABS.map(tab => tab.name), 'overview');

    return (
        <div className="flex flex-col">
            <PageTabs tabs={DASHBOARD_TABS} activeTabName={activeTabName} onSelect={onSelectTab} />
        </div>
    );
};
