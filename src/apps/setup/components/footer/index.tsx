import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { Step } from '@/apps/setup/hooks/useSteps';
import { useStepStore } from '@/apps/setup/stores/stepStore';
import { HomepageLinks } from '@/apps/setup/components/translationStep/HomepageLinks';
import { ConnectSiteButton } from '@/shared/components/connection/ConnectionPanel';

interface FooterProps
{
    currentStep: Step;
    isFirstStep: boolean;
    isLastStep: boolean;
    stepSaving?: boolean;
    nextStep: () => void;
    previousStep: () => void;
}

export const Footer = ({ currentStep, isFirstStep, isLastStep, stepSaving = false, nextStep, previousStep }: FooterProps) =>
{
    const stepError = useStepStore(state => state.stepError);

    return (
        <div className={`px-6 py-4.5 flex flex-wrap items-center gap-3 border-t border-translatewp-border ${isFirstStep ? 'justify-end' : 'justify-between'}`}>
            {!isFirstStep && (
                <Button variant='secondary' onClick={previousStep} className='rounded-sm!'>
                    {__('Previous', 'translate')}
                </Button>
            )}

            {stepError !== null && (
                <p className='m-0 flex-1 basis-full text-sm text-translatewp-error md:basis-auto' role='alert'>{stepError}</p>
            )}

            {isLastStep
                ? <HomepageLinks />
                : currentStep.id === 'connect'
                    ? <ConnectSiteButton label={__('Connect your site', 'translate')} />
                    : (
                        <Button
                            variant='primary'
                            onClick={() => void nextStep()}
                            className='rounded-sm!'
                            disabled={!currentStep?.condition() || stepSaving}
                            isBusy={stepSaving}
                        >
                            {__('Continue', 'translate')}
                        </Button>
                    )}
        </div>
    );
}
