import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ErrorInfo, ReactNode } from 'react';

interface ErrorBoundaryProps
{
    children: ReactNode;
    // Visible fallback copy. A title line and a short explanation so a crashed
    // section always says what happened instead of rendering nothing.
    title?: string;
    message?: string;
}

interface ErrorBoundaryState
{
    hasError: boolean;
    detail: string;
}

/**
 * A render guard for a self-contained panel. Without it, a throw anywhere in the
 * subtree unmounts the whole React root and leaves a blank screen with no
 * message; with it, the throw is caught, logged to the console, and replaced by
 * a visible notice while the rest of the admin keeps working.
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState>
{
    state: ErrorBoundaryState = { hasError: false, detail: '' };

    // Log from both lifecycles so a caught error always reaches the console,
    // even where a build strips componentDidCatch side effects.
    static getDerivedStateFromError( error: Error ): ErrorBoundaryState
    {
        console.error( '[TranslateWP] Section crashed', error );

        return { hasError: true, detail: String( error?.message ?? error ?? '' ).slice( 0, 300 ) };
    }

    componentDidCatch( error: Error, info: ErrorInfo ): void
    {
        console.error( '[TranslateWP] Section crashed', error, info );
    }

    render(): ReactNode
    {
        if ( ! this.state.hasError )
        {
            return this.props.children;
        }

        const title = this.props.title ?? __( 'This section could not load.', 'translate' );
        const message = this.props.message ?? __( 'Reload the page to try again. If it keeps failing, update TranslateWP from your Plugins screen.', 'translate' );

        return (
            <div
                role="alert"
                className="flex flex-col gap-1 p-6 border border-translatewp-border rounded-lg"
            >
                <span className="text-sm font-semibold text-translatewp-neutral m-0">
                    { title }
                </span>
                <span className="text-sm text-translatewp-muted m-0">
                    { message }
                </span>
                { this.state.detail && (
                    <code className="text-xs text-translatewp-muted m-0 break-all">
                        { this.state.detail }
                    </code>
                ) }
            </div>
        );
    }
}
