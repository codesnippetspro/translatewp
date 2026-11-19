import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { SwitcherView } from '@/apps/translatewp/SwitcherView';
import { managerStyles } from '@/apps/translatewp/managerStyles';
import { editMode } from '@/apps/translatewp/editMode';
import { dragHint } from '@/apps/translatewp/dragHint';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

it('renders manager and visitor surfaces for the style boundary check', async () =>
{
    const output: Record<string, string> = {};
    for (const manager of [true, false]) {
        window.TranslateWP = { canManage: manager, context: {} } as never;
        editMode.set(manager); dragHint.reset();
        const host = document.createElement('div'); document.body.appendChild(host);
        const root = createRoot(host);
        await act(async () => root.render(<SwitcherView
            languages={[{ locale: 'en', slug: 'en', active: true, default: true }, { locale: 'de', slug: 'de', active: true }]}
            switcher={{ type: 'dropdown', trigger: 'name_flag', show_flag: false } as never}
            selectedLocale="en" isOpen={true} notice={manager ? 'Manager message' : 'Visitor message'}
            noticeAction={{ label: 'Open settings', href: '#settings' }}
            triggerBindings={manager ? { 'data-drag-handle': true } : undefined}
            onOpenChange={() => {}} onLanguageSelect={() => {}} onDismissNotice={() => {}}
        />));
        output[manager ? 'manager' : 'visitor'] = host.innerHTML;
        await act(async () => root.unmount()); host.remove();
    }
    editMode.set(false);
    const dir = resolve(process.cwd(), 'e2e/.fixtures');mkdirSync(dir, { recursive: true });
    writeFileSync(resolve(dir, 'manager-style-markup.json'), JSON.stringify({ ...output, managerStyles }));
    expect(output.manager).toContain('twp-admin-item');
    expect(output.visitor).not.toContain('class="twp-admin-item');
});
