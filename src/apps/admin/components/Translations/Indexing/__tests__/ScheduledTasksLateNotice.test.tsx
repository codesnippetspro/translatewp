import { TextEncoder } from 'node:util';
import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';

if (typeof globalThis.TextEncoder === 'undefined')
{
    (globalThis as Record<string, unknown>).TextEncoder = TextEncoder;
}

(globalThis as Record<string, unknown>).IS_REACT_ACT_ENVIRONMENT = true;

import { ScheduledTasksLateNotice } from '../ScheduledTasksLateNotice';

let container: HTMLDivElement;
let root: Root;

function render(element: React.ReactElement): void
{
    container = document.createElement('div');
    document.body.appendChild(container);
    root = createRoot(container);
    act(() => root.render(element));
}

afterEach(() =>
{
    act(() => root.unmount());
    container.remove();
});

describe('ScheduledTasksLateNotice', () =>
{
    test('renders the late-tasks warning and the Sync now action when scheduled tasks are late', () =>
    {
        render(<ScheduledTasksLateNotice late={true} onSyncNow={() => {}} isSyncing={false} />);

        expect(container.textContent).toContain('Scheduled tasks are running late');
        expect(container.textContent).toContain('indexed as pages are viewed');

        const syncNow = Array.from(container.querySelectorAll('button'))
            .find(button => button.textContent?.trim() === 'Sync now');
        expect(syncNow).toBeDefined();
    });

    test('renders nothing when scheduled tasks are on time', () =>
    {
        render(<ScheduledTasksLateNotice late={false} onSyncNow={() => {}} isSyncing={false} />);

        expect(container.innerHTML).toBe('');
    });
});
