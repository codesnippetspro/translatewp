import { X } from 'lucide-react';
import { Icon } from "@/shared/components/Icon";

import { useFinishSetup } from '@/apps/setup/hooks/useFinishSetup';

export const Header = () =>
{
    const { finishSetup } = useFinishSetup();

    return (
        <div className="px-8 py-5 flex items-center justify-between border-b border-translatewp-border">
            <div className="flex items-center gap-3">
                <Icon />
                <h1 className="text-lg font-semibold text-translatewp-neutral!">TranslateWP setup</h1>
            </div>

            <button type='button' className='bg-white border-0 cursor-pointer hover:text-black transition-colors duration-200' onClick={finishSetup}>
                <X size={24} />
            </button>
        </div>
    );
}