import { useState } from '@wordpress/element';

import { ProModal } from '@/shared/components/pro/ProModal';

interface ProLockProps
{
    children: React.ReactNode;
    feature?: string;
    className?: string;
    /** Blur the gated content instead of graying it out. */
    blur?: boolean;
    /** Open the upgrade modal immediately, without waiting for a click. */
    defaultOpen?: boolean;
    /**
     * Called when the modal is dismissed. Use it to navigate away from gates that
     * leave the whole page blurred and unusable (e.g. a full-page `defaultOpen` gate).
     */
    onDismiss?: () => void;
    /** Marker pinned to the top-right corner, kept crisp above the gated content; a button in it takes its own clicks. */
    badge?: React.ReactNode;
    /** Where the badge sits; the top right corner by default. */
    badgeClassName?: string;
    /** Called once Pro is installed or activated from this lock, before the page reloads. */
    onProReady?: () => void;
}

export const ProLock = ({ children, feature, className, blur = false, defaultOpen = false, onDismiss, badge, badgeClassName = 'right-2 top-2', onProReady }: ProLockProps) =>
{
    const [isOpen, setIsOpen] = useState(defaultOpen);

    const handleClose = () =>
    {
        setIsOpen(false);
        onDismiss?.();
    };

    const gatedClasses = blur
        ? 'blur-[3px] opacity-70 pointer-events-none select-none'
        : 'opacity-50 grayscale pointer-events-none select-none';

    return (
        <div className={`relative ${className ?? ''}`}>
            <div className={gatedClasses}>
                {children}
            </div>

            <button
                type="button"
                aria-label={feature ? `Unlock ${feature} with PRO` : 'Unlock with PRO'}
                onClick={() => setIsOpen(true)}
                className="absolute inset-0 w-full h-full cursor-pointer bg-transparent border-0 p-0 m-0"
            />

            {badge && (
                <div data-pro-lock-badge className={`pointer-events-none absolute z-10 [&_button]:pointer-events-auto ${badgeClassName}`}>
                    {badge}
                </div>
            )}

            <ProModal isOpen={isOpen} onClose={handleClose} feature={feature} onProReady={onProReady} />
        </div>
    );
};
