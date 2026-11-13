import { TabPanel } from '@wordpress/components';
import { PAGE_INSET, PageBody, PageHeader } from '@/shared/components/adminLayout';

// Every wp-admin view shares one page shell: a header row, a body that fills
// the width and the same inset at every breakpoint. wp-admin sizes boxes by
// content, so the inset is counted inside the width.
export { PAGE_INSET, PageBody, PageHeader };

// Only the page's own strip (the panel's direct child) takes these; a tab
// strip inside a tab's content, such as the language scope switcher, keeps
// its own layout.
const TAB_PANEL_CLASS = '[&>div[role=tablist]_button[role=tab]]:font-bold! [&>div[role=tablist]_button[role=tab]]:text-sm! [&>div[role=tablist]_button[role=tab]]:text-translatewp-neutral! [&>div[role=tablist]_button[role=tab]]:hover:text-translatewp-primary! [&>div[role=tablist]]:border-b [&>div[role=tablist]]:border-translatewp-border [&>div[role=tablist]]:flex-wrap [&>div[role=tablist]]:px-4 md:[&>div[role=tablist]]:px-8';

// A page that lays out its own header and body (`inset: false`) renders bare.
export type NavigationPage = { name: string; title: string; content?: React.ReactNode; inset?: boolean };

export const PageTabs = ({ tabs, activeTabName, onSelect, bodyClassName }: {
    tabs: NavigationPage[];
    activeTabName: string;
    onSelect: (name: string) => void;
    bodyClassName?: string;
}) => (
    <TabPanel
        key={activeTabName}
        tabs={tabs}
        initialTabName={activeTabName}
        onSelect={onSelect}
        className={TAB_PANEL_CLASS}
        children={tab => {
            const current = tabs.find(t => t.name === tab.name);
            const content = current?.content || '';

            return current?.inset === false
                ? <div>{content}</div>
                : <PageBody className={bodyClassName ?? 'gap-8 pt-6 md:pt-8'}>{content}</PageBody>;
        }}
    />
);
