import { useMutation } from '@tanstack/react-query';

import { useSetupStore } from '@/apps/setup/stores/setupStore';
import { confirmRetranslation, contextChangedSinceLoad, isRestartedSetup } from '@/apps/setup/hooks/retranslationGate';

import { finishSetupMutationOptions } from '@/shared/mutation-options/setup';
import { useStepStore } from '@/apps/setup/stores/stepStore';
import { setupErrorMessage } from '@/apps/setup/hooks/setupErrorMessage';

export const useFinishSetup = () =>
{
    const { defaultLanguage, languages, autoTranslate, context, isScanning } = useSetupStore();

    const { mutate, isPending } = useMutation({
        ...finishSetupMutationOptions(),
        onSuccess: () => {
            window.location.reload();
        },
        onError: (error: unknown) => {
            useStepStore.getState().setStepError(setupErrorMessage(error));
        }
    });

    const enabled = () =>
    {
        return !!defaultLanguage && !isScanning;
    }

    const finishSetup = async () =>
    {
        if (!enabled()) {
            return;
        }

        const latest = useSetupStore.getState();
        if (isRestartedSetup() && contextChangedSinceLoad(latest.context) && !(await confirmRetranslation('finish'))) {
            return;
        }
        useStepStore.getState().setStepError(null);
        mutate({ defaultLanguage: latest.defaultLanguage, languages: latest.languages, autoTranslate: latest.autoTranslate, context: latest.context });
    }

    return {
        enabled,
        finishSetup,
        isPending
    };
}
