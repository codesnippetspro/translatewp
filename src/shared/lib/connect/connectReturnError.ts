import { useSyncExternalStore } from '@wordpress/element';

const listeners = new Set<VoidFunction>();
const subscribe = (listener: VoidFunction): VoidFunction =>
{
    listeners.add(listener);
    return () => { listeners.delete(listener); };
};
const getMessage = (): string => typeof window.TranslateWP?.connectReturnError === 'string'
    ? window.TranslateWP.connectReturnError : '';

export const useConnectReturnError = (): string => useSyncExternalStore(subscribe, getMessage, () => '');

export const clearConnectReturnError = (): void =>
{
    if (window.TranslateWP) window.TranslateWP.connectReturnError = '';
    for (const listener of listeners) listener();
};
