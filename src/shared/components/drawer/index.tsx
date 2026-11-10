import { useEffect, useId, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import { X } from 'lucide-react';

interface DrawerProps {
    isOpen: boolean;
    onClose: () => void;
    children: ReactNode;
    title?: string;
    className?: string;
    widthClassName?: string;
    closeOnOverlayClick?: boolean;
    closeOnEsc?: boolean;
    showCloseButton?: boolean;
}

export const Drawer = ({
    isOpen,
    onClose,
    children,
    title,
    className,
    widthClassName,
    closeOnOverlayClick = true,
    closeOnEsc = true,
    showCloseButton = true,
}: DrawerProps) =>
{
    const previousOverflowRef = useRef<string | null>( null );
    const titleId = useId();

    useEffect( () =>
    {
        if ( !isOpen )
        {
            return;
        }

        previousOverflowRef.current = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () =>
        {
            document.body.style.overflow = previousOverflowRef.current ?? '';
            previousOverflowRef.current = null;
        };
    }, [ isOpen ] );

    useEffect( () =>
    {
        if ( !isOpen || !closeOnEsc )
        {
            return;
        }

        const handleKeyDown = ( event: KeyboardEvent ) =>
        {
            if ( event.key === 'Escape' )
            {
                onClose();
            }
        };

        window.addEventListener( 'keydown', handleKeyDown );

        return () => window.removeEventListener( 'keydown', handleKeyDown );
    }, [ isOpen, closeOnEsc, onClose ] );

    // Render nothing while closed so visibility follows state, not CSS. A drawer that
    // stays mounted and only slides off with a transform reappears whenever that class
    // fails to apply, leaving an undismissable dialog its close handlers cannot hide.
    if ( ! isOpen )
    {
        return null;
    }

    return (
        <div className="fixed inset-0 z-999999 pointer-events-auto">
            <div
                className="absolute inset-0 bg-black/40 transition-opacity opacity-100"
                onClick={closeOnOverlayClick ? onClose : undefined}
            />

            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={title ? titleId : undefined}
                className={`absolute end-0 top-0 h-full bg-white shadow-xl transition-transform translate-x-0 ${widthClassName ?? 'w-80 sm:w-96'} ${className ?? ''}`}
                onClick={( event ) => event.stopPropagation()}
            >
                <div className="h-full overflow-y-auto px-7 py-8 flex flex-col gap-8">
                    {(title || showCloseButton) && (
                        <div className="flex items-center justify-between">
                            {title && <h2 id={titleId} className="m-0! text-lg font-semibold text-translatewp-neutral">{title}</h2>}
                            
                            {showCloseButton && (
                                <button
                                    type="button"
                                    className="text-translatewp-icons bg-white border-0 hover:text-translatewp-primary"
                                    onClick={onClose}
                                    aria-label={__('Close', 'translate')}
                                >
                                    <X className="w-6 h-6 text-translatewp-grip" aria-hidden="true" />
                                </button>
                            )}
                        </div>
                    )}

                    {children}
                </div>
            </div>
        </div>
    );
};
