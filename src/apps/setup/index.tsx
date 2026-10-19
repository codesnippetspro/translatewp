import { CheckCircle2 } from 'lucide-react';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from '@wordpress/element';

import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { ConnectionStatusText } from '@/shared/components/connection/ConnectionStatusText';
import { SiteLanguagePicker } from '@/apps/setup/components/siteLanguagePicker';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';
import { useConfirmStore } from '@/apps/setup/stores/confirmStore';

import { useSteps } from '@/apps/setup/hooks/useSteps';
import { Header } from '@/apps/setup/components/header';
import { StepSelector } from '@/apps/setup/components/stepSelector';
import { Footer } from '@/apps/setup/components/footer';

import { LanguagesStep } from '@/apps/setup/components/languagesStep';
import { ConnectStep } from '@/apps/setup/components/connectStep';
import { TranslationStep } from '@/apps/setup/components/translationStep';
import { ReadSiteStep } from '@/apps/setup/components/readSiteStep';
import { StyleToneStep } from '@/apps/setup/components/styleToneStep';

export const WIZARD_SHELL_CLASS = 'translatewp-app-root box-border flex min-h-screen flex-col items-center justify-center overflow-y-auto px-4 py-8';
export const WIZARD_CARD_CLASS = 'setup-card bg-white max-w-[860px] w-full rounded-2xl shadow-lg shadow-translatewp-neutral/10 flex flex-col h-[760px] shrink-0 overflow-hidden';
export const WIZARD_BODY_CLASS = 'flex-1 min-h-0 flex flex-col';

export const Setup = () =>
{
    const { currentStep, nextStep, previousStep, isFirstStep, isLastStep, finishEnabled, finishPending, stepSaving, proAction, proPending, finishWithPro } = useSteps();
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    const siteRead = useSetupStore(state => state.siteRead);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();
    const setRequest = useConfirmStore(state => state.setRequest);

    useEffect(() =>
    {
        setRequest(requestConfirmation);
        return () => setRequest(null);
    }, [requestConfirmation, setRequest]);

    return (
        <div className={WIZARD_SHELL_CLASS}>
            <div className={WIZARD_CARD_CLASS}>
                <Header />
                <StepSelector />

                <div className={WIZARD_BODY_CLASS}>
                { connectStatus?.connected && currentStep.id !== 'connect' && (
                    <div className="mx-8 mt-6 flex items-center gap-2 rounded-sm border border-translatewp-border bg-translatewp-selection px-4 py-3">
                        <CheckCircle2 size={18} className="text-translatewp-primary shrink-0" aria-hidden="true" />
                        <ConnectionStatusText />
                        { (siteRead || currentStep.id !== 'read-site') && <SiteLanguagePicker /> }
                    </div>
                ) }

                {/* Step content */}
                <section className='setup-step p-8 flex flex-col gap-8 flex-1 min-h-0 overflow-y-auto'>
                    { currentStep.id === 'connect' && <ConnectStep /> }
                    { currentStep.id === 'languages' && <LanguagesStep /> }
                    { currentStep.id === 'translation' && <TranslationStep enabled={finishEnabled} pending={finishPending} proAction={proAction} proPending={proPending} finish={() => void nextStep()} finishWithPro={() => void finishWithPro()} /> }
                    { currentStep.id === 'read-site' && <ReadSiteStep /> }
                    { currentStep.id === 'style-tone' && <StyleToneStep /> }
                </section>
                </div>

                <ConfirmDialog {...confirmDialogProps} />
                <Footer
                    currentStep={currentStep}
                    isFirstStep={isFirstStep}
                    isLastStep={isLastStep}
                    stepSaving={stepSaving}
                    nextStep={nextStep}
                    previousStep={previousStep}
                />
            </div>
        </div>
    );
}
