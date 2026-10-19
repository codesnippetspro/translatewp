import { clearSetupDraft, setupDraftKey } from '@/shared/lib/setupDraft';

describe('the wizard draft key and its reset agree', () =>
{
    afterEach(() =>
    {
        localStorage.clear();
    });

    it('clears the draft stored under the per-path key, and only that one', () =>
    {
        window.history.replaceState(null, '', '/shop/wp-admin/admin.php?page=translatewp');
        localStorage.setItem(setupDraftKey(), JSON.stringify({ state: { languages: ['de'] } }));
        localStorage.setItem('translatewp-setup:/wp-admin/admin.php', JSON.stringify({ state: { languages: ['fr'] } }));

        clearSetupDraft();

        expect(localStorage.getItem(setupDraftKey())).toBeNull();
        expect(localStorage.getItem('translatewp-setup:/wp-admin/admin.php')).not.toBeNull();
    });
});
