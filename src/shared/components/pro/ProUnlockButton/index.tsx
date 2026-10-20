import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { Lock } from 'lucide-react';

import { TWP_BUTTON_PRIMARY } from '@/shared/components/buttonStyles';
import { ProModal } from '@/shared/components/pro/ProModal';

// The one button every Orbital-only surface offers: it names the feature and
// opens the Pro prompt, which sends an entitled site to install instead of buy.
export const ProUnlockButton = ({ feature, className = '' }: { feature: string; className?: string }) =>
{
    const [isOpen, setIsOpen] = useState(false);

    return (
        <>
            <Button variant="primary" className={`${TWP_BUTTON_PRIMARY} w-fit ${className}`.trim()} onClick={() => setIsOpen(true)} data-twp-unlock={feature}>
                <Lock size={14} aria-hidden="true" />
                {sprintf(
                    /* translators: %s: name of the locked feature. */
                    __('Unlock %s', 'translate'),
                    feature
                )}
            </Button>
            <ProModal isOpen={isOpen} onClose={() => setIsOpen(false)} feature={feature} />
        </>
    );
};
