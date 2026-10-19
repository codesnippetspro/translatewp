import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { useAdminSettingsStore } from '@/apps/admin/stores/settingsStore';

export const SlugClashActions = () =>
{
    const slugClash = useAdminSettingsStore(state => state.slugClash);
    const isSaving = useAdminSettingsStore(state => state.isSaving);
    const acceptSuggestedSlug = useAdminSettingsStore(state => state.acceptSuggestedSlug);
    const requestSlugFocus = useAdminSettingsStore(state => state.requestSlugFocus);

    if (!slugClash) {
        return null;
    }

    return (
        <>
            <Button
                variant='primary'
                className='rounded-sm! text-sm! w-fit'
                disabled={isSaving}
                isBusy={isSaving}
                onClick={() => void acceptSuggestedSlug()}
            >
                {/* translators: %s: the suggested address slug, for example de-de */}
                {sprintf(__('Use %s', 'translate'), slugClash.suggestedSlug)}
            </Button>
            <Button
                variant='secondary'
                className='rounded-sm! text-sm! w-fit'
                disabled={isSaving}
                onClick={requestSlugFocus}
            >
                {__('Choose another slug', 'translate')}
            </Button>
        </>
    );
};
