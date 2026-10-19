import { Check } from 'lucide-react';
import type { ReactNode } from 'react';

import { OPTION_ROW_CLASS, OPTION_ROW_DESCRIPTION_CLASS, OPTION_ROW_LABEL_CLASS, OPTION_ROW_TEXT_CLASS } from '@/apps/admin/components/Languages/Switcher/optionGridClass';

interface OptionRowProps
{
    label: ReactNode;
    description: string;
    isSelected: boolean;
}

export const OptionRow = ({ label, description, isSelected }: OptionRowProps) => (
    <div className={OPTION_ROW_CLASS}>
        <div className={OPTION_ROW_TEXT_CLASS}>
            <span className={`${OPTION_ROW_LABEL_CLASS} ${isSelected ? 'text-translatewp-primary!' : 'text-translatewp-neutral!'}`}>
                {label}
            </span>
            <span className={OPTION_ROW_DESCRIPTION_CLASS}>{description}</span>
        </div>

        <Check data-option-check aria-hidden="true" className={`mt-0.5 w-4.5 h-4.5 shrink-0 text-translatewp-primary!${isSelected ? '' : ' invisible'}`} />
    </div>
);
