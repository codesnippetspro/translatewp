import { useMutation, useQuery } from '@tanstack/react-query';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useStepStore } from '@/apps/setup/stores/stepStore';
import { useFinishSetup } from '@/apps/setup/hooks/useFinishSetup';
import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { getConnectStatusQueryOptions } from '@/shared/query-options/connect';
import { saveSetupStepMutationOptions } from '@/shared/mutation-options/setup';
import { setupErrorMessage } from '@/apps/setup/hooks/setupErrorMessage';
import { activatePro, installPro } from '@/shared/api/connect';
import { pendingProAction } from '@/shared/lib/pro/proState';
import { confirmRetranslation, isRestartedSetup, styleChangedSinceLoad } from '@/apps/setup/hooks/retranslationGate';

export type Step = { id: string, label: string, condition: () => boolean };

export const useSteps = () =>
{
    const { defaultLanguage, languages, autoTranslate, context, siteRead } = useSetupStore();
    const { data: connectStatus } = useQuery(getConnectStatusQueryOptions());
    const { mutateAsync: saveStep, isPending: stepSaving } = useMutation(saveSetupStepMutationOptions());
    const steps: Step[] = [
        {
            id: 'connect',
            label: __('Connect', 'translate'),
            condition: () => connectStatus?.connected === true,
        },
        {
            id: 'read-site',
            label: __('Read your site', 'translate'),
            condition: () => siteRead
        },
        {
            id: 'style-tone',
            label: __('Style and tone', 'translate'),
            condition: () => true
        },
        {
            id: 'languages',
            label: __('Languages', 'translate'),
            condition: () => {
                return !!defaultLanguage;
            }
        },
        {
            id: 'translation',
            label: __('Translation', 'translate'),
            condition: () => true
        }
    ];

    const { step, setStep, setStepError } = useStepStore();
    const { finishSetup, enabled, isPending } = useFinishSetup();
    const [proPending, setProPending] = useState(false);

    // The connect step is complete the moment the site is connected. On return
    // from the portal the wizard must not rest on it, so the first navigable
    // step becomes languages. Both the selected tab and the rendered step read
    // this one derived index, so they can never fall out of sync.
    const firstNavigableIndex = connectStatus?.connected === true ? 1 : 0;
    const storedIndex = steps.findIndex( stepItem => stepItem.id === step );
    const currentIndex = Math.max( storedIndex, firstNavigableIndex );
    const currentStep: Step = steps[ currentIndex ];
    const isFirstStep = currentIndex === firstNavigableIndex;
    const isLastStep = currentIndex === steps.length - 1;

    // A save carries what the store holds the moment Continue is pressed, not
    // what this render saw: a pick made in the same tick as the click (a
    // scripted or keyboard-fast sequence) must never be saved as no languages.
    // False when the owner cancelled a save that would translate the site again.
    const persistCurrentStep = async (): Promise<boolean> =>
    {
        const nextStepId = steps[ currentIndex + 1 ].id;
        const { defaultLanguage, languages, autoTranslate, context, style, tone, formality, creativity } = useSetupStore.getState();
        if (currentStep.id === 'style-tone') {
            if (isRestartedSetup() && styleChangedSinceLoad({ style, tone, formality, creativity }) && !(await confirmRetranslation('style'))) {
                useSetupStore.setState({
                    style: window.setupData?.style ?? style,
                    tone: window.setupData?.tone ?? tone,
                    formality: window.setupData?.formality ?? 5,
                    creativity: window.setupData?.creativity ?? 5,
                });
                return false;
            }
            await saveStep({ step: 'style-tone', resumeStep: nextStepId, style, tone, formality, creativity });
        }
        else if (currentStep.id === 'languages') {
            useSetupStore.getState().setLanguagesSavedAt(Date.now());
            await saveStep({ step: 'languages', resumeStep: nextStepId, defaultLanguage, languages, context });
        }
        else if (currentStep.id === 'translation') {
            await saveStep({ step: 'translation', resumeStep: nextStepId, autoTranslate });
        }
        else if (currentStep.id === 'read-site') {
            await saveStep({ step: 'read-site', resumeStep: nextStepId });
        }

        return true;
    };

    const nextStep = async () =>
    {
        if ( isLastStep ) {
            void finishSetup();
            return;
        }

        setStepError( null );
        try {
            if ( !( await persistCurrentStep() ) ) {
                return;
            }
        } catch ( error ) {
            setStepError( setupErrorMessage( error ) );
            return;
        }
        setStep( steps[ currentIndex + 1 ].id );
    };

    // The last step of an entitled site without an active addon
    // installs or activates Pro, then finishes. A failed install says why and
    // leaves "Finish without Pro" to complete setup anyway.
    const proAction = isLastStep ? pendingProAction(connectStatus) : null;

    const finishWithPro = async () =>
    {
        if ( proAction === null || !enabled() ) {
            return;
        }

        const fallback = proAction === 'install'
            ? __('Pro could not be installed. Try again, or finish without Pro and install it from the Dashboard.', 'translate')
            : __('Pro could not be activated. Try again, or finish without Pro and activate it from the Dashboard.', 'translate');

        setStepError( null );
        setProPending( true );
        try {
            const result = proAction === 'install' ? await installPro() : await activatePro();
            if ( !result.ok ) {
                setStepError( result.message || fallback );
                setProPending( false );
                return;
            }
        } catch {
            setStepError( fallback );
            setProPending( false );
            return;
        }
        setProPending( false );
        void finishSetup();
    };

    const previousStep = () =>
    {
        if ( isFirstStep ) {
            return;
        }
        setStepError( null );
        setStep( steps[ currentIndex - 1 ].id );
    };

    const setStepWithId = ( stepId: string ) =>
    {
        setStep( stepId );
    };

    return {
        steps,
        currentStep,
        currentIndex,
        isFirstStep,
        isLastStep,
        finishEnabled: enabled(),
        finishPending: isPending,
        stepSaving,
        proAction,
        proPending,
        finishWithPro,
        nextStep,
        previousStep,
        setStepWithId
    };
}
