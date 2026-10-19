import { Button, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Sparkles } from 'lucide-react';

import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { TranslationProgressTable } from '@/apps/setup/components/translationStep/TranslationProgressTable';

export interface FinishProps
{
    enabled: boolean;
    pending: boolean;
    proAction: 'install' | 'activate' | null;
    proPending: boolean;
    finish: () => void;
    finishWithPro: () => void;
}

const FinishAction = ({ enabled, pending, proAction, proPending, finish, finishWithPro }: FinishProps) =>
{
    const disabled = !enabled || pending || proPending;

    if (proAction === null)
    {
        return (
            <Button variant='primary' className='rounded-sm!' onClick={finish} disabled={disabled} isBusy={pending} data-finish-setup>
                {__('Open Dashboard', 'translate')}
            </Button>
        );
    }

    return (
        <div className='flex flex-wrap items-center gap-3'>
            <Button variant='primary' className='rounded-sm!' onClick={finishWithPro} disabled={disabled} isBusy={pending || proPending} data-finish-setup>
                {proAction === 'install' ? __('Install Pro and finish', 'translate') : __('Activate Pro and finish', 'translate')}
            </Button>
            <Button variant='tertiary' onClick={finish} disabled={disabled}>
                {__('Finish without Pro', 'translate')}
            </Button>
        </div>
    );
};

export const TranslationStep = (finishProps: FinishProps) =>
{
    const { autoTranslate, setAutoTranslate, languages } = useSetupStore();
    const { proAction } = finishProps;

    return (
        <div className='setup-translation-step'>
            {proAction !== null && (
                <div className='flex items-start gap-3 rounded-sm border border-translatewp-primary bg-translatewp-primary/5 px-4 py-3' role='status'>
                    <Sparkles size={18} className='mt-0.5 shrink-0 text-translatewp-primary' aria-hidden='true' />
                    <div className='flex flex-col gap-1'>
                        <p className='m-0 text-sm font-semibold text-translatewp-neutral'>{__('Your account includes Pro', 'translate')}</p>
                        <p className='m-0 text-sm text-translatewp-neutral'>
                            {proAction === 'install'
                                ? __('Install Pro and finish adds it to this site and completes setup in one step.', 'translate')
                                : __('It is installed on this site but not active. Activate Pro and finish turns it on and completes setup in one step.', 'translate')}
                        </p>
                    </div>
                </div>
            )}
            <ToggleControl
                label={__("Auto-translate based on the visitor's browser language", 'translate')}
                checked={autoTranslate}
                onChange={setAutoTranslate}
                className='[&_.components-flex]:gap-4! mb-0! text-sm!'
            />
            <TranslationProgressTable languages={languages} action={<FinishAction {...finishProps} />} />
        </div>
    );
}
