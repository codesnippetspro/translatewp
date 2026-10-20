import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import { activatePro, installPro } from '@/shared/api/connect';
import { TWP_BUTTON_PRIMARY } from '@/shared/components/buttonStyles';
import { reloadPage } from '@/shared/lib/reloadPage';

interface ProInstallActionProps
{
    step: 'install' | 'activate';
    label: string;
    className?: string;
    onDone?: () => void;
}

const DEFAULT_CLASS = TWP_BUTTON_PRIMARY;

/**
 * One-click install or activation of TranslateWP Pro. Plain async with local
 * busy state, so it works outside a react-query provider too. A successful
 * run reloads the page, so the server-rendered menu and the page-load Pro
 * state catch up.
 */
export const ProInstallAction = ({ step, label, className, onDone }: ProInstallActionProps) =>
{
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const fallback = step === 'install'
        ? __('Pro could not be installed. Try again, or install it from your account.', 'translate')
        : __('Pro could not be activated. Activate it from your Plugins screen.', 'translate');

    const run = async () =>
    {
        setBusy(true);
        setError(null);
        try {
            const result = step === 'install' ? await installPro() : await activatePro();
            if (result.ok) {
                onDone?.();
                reloadPage();
                return;
            }
            setError(result.message || fallback);
        } catch {
            setError(fallback);
        }
        setBusy(false);
    };

    return (
        <span className="inline-flex flex-col items-stretch gap-1">
            <button
                type="button"
                disabled={busy}
                aria-busy={busy}
                onClick={() => { void run(); }}
                className={className ?? DEFAULT_CLASS}
            >
                {label}
            </button>
            {error && (
                <span className="text-sm text-translatewp-error" role="alert">{error}</span>
            )}
        </span>
    );
};
