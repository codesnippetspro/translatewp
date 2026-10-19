import { activatePro, beginConnect, disconnectSite, installPro, reconnectSite } from '@/shared/api/connect';

export const installProMutationOptions = () => ({
    mutationFn: installPro,
});

export const activateProMutationOptions = () => ({
    mutationFn: activatePro,
});

export const beginConnectMutationOptions = () => ({
    mutationFn: beginConnect,
});

export const reconnectSiteMutationOptions = () => ({
    mutationFn: reconnectSite,
});

export const disconnectSiteMutationOptions = () => ({
    mutationFn: disconnectSite,
});
