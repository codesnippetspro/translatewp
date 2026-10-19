import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Sparkles, X } from 'lucide-react';

import type { ProCapabilities } from '@/shared/api/connect';
import { dismissProWelcome } from '@/shared/api/admin';
import { proWelcomePending } from '@/shared/lib/pro/proState';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';

type ProWelcomeItem = {
    label: string;
    description: string;
    href: string;
    capability?: keyof ProCapabilities;
};

export const proWelcomeItems = (capabilities: Partial<ProCapabilities> | undefined, homeUrl: string): ProWelcomeItem[] =>
{
    const items: ProWelcomeItem[] = [
        {
            label: __('SEO Tools', 'translate'),
            description: __('Translated titles, descriptions and sitemaps for search engines.', 'translate'),
            href: 'admin.php?page=translatewp-seo',
            capability: 'seo',
        },
        {
            label: __('Custom style and tone', 'translate'),
            description: __('Describe the voice your translations should keep.', 'translate'),
            href: 'admin.php?page=translatewp-settings#translation',
            capability: 'custom_style',
        },
        {
            label: __('Lexicon scan', 'translate'),
            description: __('Find the names and terms your site uses and decide how each one translates.', 'translate'),
            href: 'admin.php?page=translatewp-lexicon',
        },
        {
            label: __('Edit translations and placement on the page', 'translate'),
            description: __('Open your site, switch to a translation language and use the switcher menu.', 'translate'),
            href: homeUrl,
        },
        {
            label: __('Your own AI key', 'translate'),
            description: __('Translate with your own AI provider account.', 'translate'),
            href: 'admin.php?page=translatewp-settings#ai-model',
            capability: 'byok',
        },
    ];

    return items.filter(item => item.href !== '' && (item.capability === undefined || capabilities?.[item.capability] === true));
};

/**
 * Once Pro is active on the site, the Dashboard shows this panel in
 * the install card's place, once, until the owner dismisses it for good.
 */
export const ProWelcome = () =>
{
    const queryClient = useQueryClient();
    const { data } = useQuery(getConnectStatusQueryOptions());

    const dismiss = useMutation({
        mutationFn: dismissProWelcome,
        onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['connectStatus'] }); },
    });

    if (!proWelcomePending(data) || dismiss.isSuccess) {
        return null;
    }

    const items = proWelcomeItems(data?.pro?.capabilities, window.TranslateWP?.context?.home_url ?? '');

    return (
        <div
            data-testid="pro-welcome"
            role="status"
            className="flex items-start gap-4 rounded-lg border border-translatewp-primary bg-translatewp-primary/5 px-5 py-4"
        >
            <Sparkles size={22} className="mt-0.5 shrink-0 text-translatewp-primary" aria-hidden="true" />
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                <p className="m-0 text-base font-semibold text-translatewp-neutral">{__('Welcome to Pro', 'translate')}</p>
                <p className="m-0 text-sm text-translatewp-neutral">{__('TranslateWP Pro is active on this site. Here is what it unlocked:', 'translate')}</p>
                <ul className="m-0 flex flex-col gap-1.5 p-0">
                    {items.map(item => (
                        <li key={item.label} className="m-0 text-sm text-translatewp-neutral">
                            <a href={item.href} className="font-medium text-translatewp-primary underline">{item.label}</a>
                            <span className="text-translatewp-muted">: {item.description}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <Button
                size="small"
                icon={<X size={16} aria-hidden="true" />}
                label={__('Dismiss', 'translate')}
                disabled={dismiss.isPending}
                onClick={() => dismiss.mutate()}
            />
        </div>
    );
};
