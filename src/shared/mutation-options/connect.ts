import { activatePro, disconnectSite, installPro } from '@/shared/api/connect';

export const installProMutationOptions = () => ({
    mutationFn: installPro,
});

export const activateProMutationOptions = () => ({
    mutationFn: activatePro,
});

export const disconnectSiteMutationOptions = () => ({
    mutationFn: disconnectSite,
});
