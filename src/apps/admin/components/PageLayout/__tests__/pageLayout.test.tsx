import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { PageBody, PageHeader, PageTabs } from '@/apps/admin/components/PageLayout';
import { FeedbackForm } from '@/apps/admin/components/Transparency/FeedbackForm';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

jest.mock('@/shared/api/admin', () => ({
    submitPluginFeedback: jest.fn(),
}));

const render = async (node: React.ReactNode): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    await act(async () =>
    {
        createRoot(container).render(
            <QueryClientProvider client={queryClient}>{node}</QueryClientProvider>
        );
    });
    return container;
};

const hasWidthCap = (element: Element | null): boolean => /(^|\s)max-w-/.test(element?.className ?? '');

describe('page layout', () =>
{
    afterEach(() => { document.body.innerHTML = ''; });

    it('a page header carries the title, badge, description and actions in one full-width row with the shared inset', async () =>
    {
        const container = await render(
            <PageHeader title="Lexicon" badge={<span>Pro</span>} description="Manage terms" actions={<button>Add term</button>} />
        );
        const header = container.querySelector('[data-page-header]') as HTMLElement;

        expect(header.className).toContain('box-border');
        expect(header.className).toContain('px-4');
        expect(header.className).toContain('md:px-8');
        expect(header.className).toContain('flex-wrap');
        expect(hasWidthCap(header)).toBe(false);
        expect(header.textContent).toContain('Lexicon');
        expect(header.textContent).toContain('Pro');
        expect(header.textContent).toContain('Manage terms');
        expect(header.querySelector('button')?.textContent).toBe('Add term');
    });

    it('a page body fills the width with the same inset and no width cap', async () =>
    {
        const container = await render(<PageBody><span>content</span></PageBody>);
        const body = container.querySelector('[data-page-body]') as HTMLElement;

        expect(body.className).toContain('w-full');
        expect(body.className).toContain('box-border');
        expect(body.className).toContain('px-4');
        expect(body.className).toContain('md:px-8');
        expect(hasWidthCap(body)).toBe(false);
    });

    it('page tabs wrap a tab in the page body unless the tab lays itself out', async () =>
    {
        const tabs = [
            { name: 'own', title: 'Own', content: <span>own layout</span>, inset: false },
            { name: 'plain', title: 'Plain', content: <span>plain</span> },
        ];
        const own = await render(<PageTabs tabs={tabs} activeTabName="own" onSelect={() => undefined} />);
        expect(own.textContent).toContain('own layout');
        expect(own.querySelector('[data-page-body]')).toBeNull();

        const plain = await render(<PageTabs tabs={tabs} activeTabName="plain" onSelect={() => undefined} />);
        expect(plain.querySelector('[data-page-body]')?.textContent).toContain('plain');
    });

    it('only the page tab strip takes the inset and wrap, a tab strip inside a tab keeps its own layout', async () =>
    {
        const tabs = [
            { name: 'plain', title: 'Plain', content: <div role="tablist" aria-label="Language" className="flex">inner</div> },
        ];
        const container = await render(<PageTabs tabs={tabs} activeTabName="plain" onSelect={() => undefined} />);
        const panel = container.querySelector('[role=tablist]')?.parentElement as HTMLElement;

        expect(panel.className).toContain('[&>div[role=tablist]]:px-4');
        expect(panel.className).toContain('md:[&>div[role=tablist]]:px-8');
        expect(panel.className).toContain('[&>div[role=tablist]]:flex-wrap');
        expect(panel.className).not.toContain('[&_div[role=tablist]]');
        expect(container.querySelector('[aria-label="Language"]')?.parentElement).not.toBe(panel);
    });

    it('the feedback form stretches across the page instead of stopping at a fixed width', async () =>
    {
        const container = await render(<FeedbackForm />);
        const form = container.querySelector('form') as HTMLElement;

        expect(form.className).toContain('w-full');
        expect(hasWidthCap(form)).toBe(false);
        expect(hasWidthCap(container.querySelector('textarea')?.closest('[class*="max-w-"]') ?? null)).toBe(false);
    });
});
