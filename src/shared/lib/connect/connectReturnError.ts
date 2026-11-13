import { useSyncExternalStore } from '@wordpress/element';

export type ConnectReturnErrorData = {
    code: string;
    message: string;
    portalSitesUrl: string;
    supportUrl: string;
};

const listeners = new Set<VoidFunction>();
const subscribe = (listener: VoidFunction): VoidFunction =>
{
    listeners.add(listener);
    return () => { listeners.delete(listener); };
};
const getError = (): ConnectReturnErrorData | null =>
{
    const error = Reflect.get(window.TranslateWP ?? {}, 'connectReturnError');
    return error && typeof error === 'object' && typeof error.message === 'string'
        && typeof error.code === 'string' && typeof error.portalSitesUrl === 'string'
        && typeof error.supportUrl === 'string' ? error : null;
};

export const useConnectReturnError = (): ConnectReturnErrorData | null => useSyncExternalStore(subscribe, getError, () => null);

export const clearConnectReturnError = (): void =>
{
    if (window.TranslateWP) Reflect.set(window.TranslateWP, 'connectReturnError', null);
    for (const listener of listeners) listener();
};
