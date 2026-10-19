import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { SwitcherView } from '@/apps/translatewp/SwitcherView';

/**
 * The switcher on a site that serves no visitors, shown only to a user who
 * can manage the plugin: it has no languages to offer, so it carries the
 * reason and a link to the fix instead.
 */
export const AdminSetupSwitcher = () =>
{
    const [dismissed, setDismissed] = useState(false);
    const setupNotice = window.TranslateWP?.context?.setup_notice;

    if (window.TranslateWP?.canManage !== true || !setupNotice)
    {
        return null;
    }

    const reconnect = setupNotice.state === 'reconnect';

    return (
        <SwitcherView
            languages={[]}
            switcher={{ ...(window.TranslateWP.options?.languages?.switcher ?? {}), type: 'dropdown', trigger: 'icon' }}
            selectedLocale=""
            isOpen={false}
            notice={dismissed ? null : (reconnect
                ? __('TranslateWP is disconnected.', 'translate')
                : __('TranslateWP is not set up yet.', 'translate'))}
            noticeAction={{
                label: reconnect ? __('Reconnect', 'translate') : __('Finish setup', 'translate'),
                href: setupNotice.url,
            }}
            onOpenChange={() => setDismissed(false)}
            onLanguageSelect={() => undefined}
            onDismissNotice={() => setDismissed(true)}
        />
    );
};
