import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

import { mailtoFallback, SupportPanel } from '@/apps/admin/components/Transparency/SupportPanel';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const mockDebug = jest.fn();
const mockTicket = jest.fn();
const mockConnect = jest.fn();
jest.mock('@/shared/api/admin', () => ({
    ...jest.requireActual('@/shared/api/admin'),
    getDebugInfo: () => mockDebug(),
    openSupportTicket: (name: string, message: string, email: string) => mockTicket(name, message, email),
    submitPluginFeedback: () => Promise.resolve({ success: true }),
}));
jest.mock('@/shared/api/connect', () => ({
    ...jest.requireActual('@/shared/api/connect'),
    getConnectStatus: () => mockConnect(),
}));

const REPORT = '== TranslateWP support report ==\nVersion: 0.10.23\n';
let root: Root | null = null;

const render = async (): Promise<HTMLElement> =>
{
    const container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    await act(async () =>
    {
        root?.render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><SupportPanel /></QueryClientProvider>);
    });
    await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });
    return container;
};

describe('the Support tab', () =>
{
    beforeEach(() =>
    {
        mockDebug.mockResolvedValue({ text: REPORT });
        mockConnect.mockResolvedValue({ connected: true, pro: { entitled: false, installed: false, active: false } });
        Object.assign(navigator, { clipboard: { writeText: jest.fn().mockResolvedValue(undefined) } });
        window.TranslateWP = { context: { user_email: 'owner@example.com' } } as typeof window.TranslateWP;
    });

    afterEach(async () =>
    {
        await act(async () => { root?.unmount(); });
        root = null;
        document.body.innerHTML = '';
    });

    it('leads with the feedback card, then the read-only report that selects itself on click, then copies it', async () =>
    {
        const container = await render();
        const order = container.textContent ?? '';
        expect(order.indexOf('Help shape TranslateWP')).toBeLessThan(order.indexOf('Need a hand?'));
        expect(order.indexOf('Need a hand?')).toBeLessThan(order.indexOf('Support report'));
        // Asking for help and giving feedback sit side by side; the report has its own row.
        const row = container.querySelector('[data-support-row]') as HTMLElement;
        expect(row.className).toMatch(/\bgrid\b/);
        expect(row.className).toMatch(/lg:grid-cols-2/);
        expect(row.textContent).toContain('Help shape TranslateWP');
        expect(row.textContent).toContain('Need a hand?');
        expect(row.textContent).not.toContain('Support report');

        const box = container.querySelector('[data-debug-report]') as HTMLTextAreaElement;
        expect(box.readOnly).toBe(true);
        expect(box.value).toBe(REPORT);
        box.select = jest.fn();
        await act(async () => { box.click(); });
        expect(box.select).toHaveBeenCalled();

        await act(async () => { (container.querySelector('[data-copy-debug]') as HTMLButtonElement).click(); });
        expect(navigator.clipboard.writeText).toHaveBeenCalledWith(REPORT);
        expect(container.textContent).toContain('Copied');
    });

    it('locks Open a ticket behind Pro on a Terrestrial site', async () =>
    {
        const container = await render();
        expect(container.querySelector('[data-open-ticket]')).toBeNull();
        expect(container.querySelector('[data-twp-unlock="support tickets"]')).not.toBeNull();
    });

    it('with Pro, sends the ticket by email and falls back to a mail link when the site cannot send', async () =>
    {
        mockConnect.mockResolvedValue({ connected: true, pro: { entitled: true, installed: true, active: true } });
        mockTicket.mockResolvedValue({ sent: false, address: 'team@translatewp.ai', subject: '[TranslateWP] Support request from example.com' });
        const open = jest.spyOn(window, 'open').mockImplementation(() => null);
        const container = await render();
        const order = container.textContent ?? '';
        expect(order.indexOf('Need a hand?')).toBeLessThan(order.indexOf('Help shape TranslateWP'));

        await act(async () => { (container.querySelector('[data-open-ticket]') as HTMLButtonElement).click(); });
        const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        expect(dialog).not.toBeNull();
        const name = dialog.querySelector('input') as HTMLInputElement;
        const message = dialog.querySelector('textarea') as HTMLTextAreaElement;
        const setValue = (element: HTMLInputElement | HTMLTextAreaElement, value: string) =>
        {
            const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value')?.set;
            setter?.call(element, value);
            element.dispatchEvent(new Event('input', { bubbles: true }));
        };
        await act(async () => { setValue(name, 'Ann'); });
        await act(async () => { setValue(message, 'The switcher is missing'); });
        // The reply address is shown, the account email to start with, and can be changed.
        const email = dialog.querySelector('input[type="email"]') as HTMLInputElement;
        expect(email.value).toBe('owner@example.com');
        await act(async () => { setValue(email, 'ann@studio.test'); });
        const send = dialog.querySelector('[data-send-ticket]') as HTMLButtonElement;
        expect(send.disabled).toBe(false);
        await act(async () => { send.click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(mockTicket).toHaveBeenCalledWith('Ann', 'The switcher is missing', 'ann@studio.test');
        expect(open).toHaveBeenCalledWith(expect.stringMatching(/^mailto:team@translatewp\.ai\?subject=/), '_blank', 'noopener');
        // The mail the user's app opens carries the reply address they chose.
        expect(decodeURIComponent(String(open.mock.calls[0][0]))).toContain('Reply to: ann@studio.test');
        expect(document.body.textContent).toContain('your mail app opened');
        open.mockRestore();
    });

    it('builds a mail link that carries the message, the name and the report', () =>
    {
        const link = mailtoFallback('Ann', 'Hello', 'Subject', REPORT);
        expect(link.startsWith('mailto:team@translatewp.ai?subject=Subject&body=')).toBe(true);
        expect(decodeURIComponent(link.split('body=')[1])).toContain('== TranslateWP support report ==');
    });

    it('keeps an address that is not an email in the form, with a message, and sends nothing', async () =>
    {
        mockConnect.mockResolvedValue({ connected: true, pro: { entitled: true, installed: true, active: true } });
        mockTicket.mockReset();
        const open = jest.spyOn(window, 'open').mockImplementation(() => null);
        const container = await render();
        await act(async () => { (container.querySelector('[data-open-ticket]') as HTMLButtonElement).click(); });
        const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        const setValue = (element: HTMLInputElement | HTMLTextAreaElement, value: string) =>
        {
            const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value')?.set;
            setter?.call(element, value);
            element.dispatchEvent(new Event('input', { bubbles: true }));
        };
        await act(async () => { setValue(dialog.querySelector('input') as HTMLInputElement, 'Ann'); });
        await act(async () => { setValue(dialog.querySelector('textarea') as HTMLTextAreaElement, 'Help'); });
        await act(async () => { setValue(dialog.querySelector('input[type="email"]') as HTMLInputElement, 'ann at studio'); });

        const send = dialog.querySelector('[data-send-ticket]') as HTMLButtonElement;
        expect(send.disabled).toBe(true);
        expect(dialog.textContent).toContain('Enter an email address we can answer.');
        expect(mockTicket).not.toHaveBeenCalled();
        expect(open).not.toHaveBeenCalled();
        open.mockRestore();
    });

    it('shows the server refusing the address in the form instead of opening a mail link', async () =>
    {
        mockConnect.mockResolvedValue({ connected: true, pro: { entitled: true, installed: true, active: true } });
        mockTicket.mockReset().mockRejectedValue({ code: 'invalid_reply_address', message: 'The reply address is not an email address', data: { status: 400 } });
        const open = jest.spyOn(window, 'open').mockImplementation(() => null);
        const container = await render();
        await act(async () => { (container.querySelector('[data-open-ticket]') as HTMLButtonElement).click(); });
        const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        const setValue = (element: HTMLInputElement | HTMLTextAreaElement, value: string) =>
        {
            const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value')?.set;
            setter?.call(element, value);
            element.dispatchEvent(new Event('input', { bubbles: true }));
        };
        await act(async () => { setValue(dialog.querySelector('input') as HTMLInputElement, 'Ann'); });
        await act(async () => { setValue(dialog.querySelector('textarea') as HTMLTextAreaElement, 'Help'); });
        await act(async () => { (dialog.querySelector('[data-send-ticket]') as HTMLButtonElement).click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(open).not.toHaveBeenCalled();
        expect(document.querySelector('[role="dialog"]')?.textContent).toContain('Enter an email address we can answer.');
        open.mockRestore();
    });

    it('a message the server refuses for its length does not mark a valid reply address as wrong', async () =>
    {
        mockConnect.mockResolvedValue({ connected: true, pro: { entitled: true, installed: true, active: true } });
        mockTicket.mockReset().mockRejectedValue({ code: 'invalid_parameter', message: 'Message is too long', data: { status: 400 } });
        const open = jest.spyOn(window, 'open').mockImplementation(() => null);
        const container = await render();
        await act(async () => { (container.querySelector('[data-open-ticket]') as HTMLButtonElement).click(); });
        const dialog = document.querySelector('[role="dialog"]') as HTMLElement;
        const setValue = (element: HTMLInputElement | HTMLTextAreaElement, value: string) =>
        {
            const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value')?.set;
            setter?.call(element, value);
            element.dispatchEvent(new Event('input', { bubbles: true }));
        };
        await act(async () => { setValue(dialog.querySelector('input') as HTMLInputElement, 'Ann'); });
        await act(async () => { setValue(dialog.querySelector('textarea') as HTMLTextAreaElement, 'a'.repeat(10001)); });
        await act(async () => { (dialog.querySelector('[data-send-ticket]') as HTMLButtonElement).click(); });
        await act(async () => { await new Promise(resolve => setTimeout(resolve, 0)); });

        expect(document.querySelector('[data-reply-error]')).toBeNull();
        expect(open).toHaveBeenCalledWith(expect.stringMatching(/^mailto:/), '_blank', 'noopener');
        open.mockRestore();
    });
});
