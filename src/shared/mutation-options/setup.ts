import { finishSetup, saveSetupStep, scanContext, type SaveStepInput } from '@/shared/api/setup';

export const scanContextMutationOptions = () =>
{
    return {
        mutationFn: scanContext,
    };
};

export const saveSetupStepMutationOptions = () =>
{
    return {
        mutationFn: (input: SaveStepInput) => saveSetupStep(input),
    };
};

export const finishSetupMutationOptions = () =>
{
    return {
        mutationFn: (variables: { defaultLanguage: string, languages: string[], autoTranslate: boolean, context: string }) =>
            finishSetup(variables.defaultLanguage, variables.languages, variables.autoTranslate, variables.context)
    };
}
