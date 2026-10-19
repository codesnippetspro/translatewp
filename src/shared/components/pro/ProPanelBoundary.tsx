import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ErrorInfo, ReactNode } from 'react';

interface ProPanelBoundaryProps
{
    children: ReactNode;
}

interface ProPanelBoundaryState
{
    hasError: boolean;
}

export class ProPanelBoundary extends Component<ProPanelBoundaryProps, ProPanelBoundaryState>
{
    state: ProPanelBoundaryState = { hasError: false };

    // Log from both error lifecycles so a failing Pro panel always surfaces in
    // the console, even where a build strips componentDidCatch side effects.
    static getDerivedStateFromError( error: Error ): ProPanelBoundaryState
    {
        console.error( '[TranslateWP] Pro panel failed', error );

        return { hasError: true };
    }

    componentDidCatch( error: Error, info: ErrorInfo ): void
    {
        console.error( '[TranslateWP] Pro panel failed', error, info );
    }

    render(): ReactNode
    {
        if ( ! this.state.hasError )
        {
            return this.props.children;
        }

        return (
            <div className="flex flex-col gap-2 p-6 border border-translatewp-border rounded-lg">
                <span className="text-sm text-translatewp-muted!">
                    { __( 'This Pro panel could not load. Reload the page. If it keeps failing, update TranslateWP and TranslateWP Pro from your Plugins screen.', 'translate' ) }
                </span>
                <a href="plugins.php" className="text-sm text-translatewp-primary! w-fit">
                    { __( 'Open the Plugins screen', 'translate' ) }
                </a>
            </div>
        );
    }
}
