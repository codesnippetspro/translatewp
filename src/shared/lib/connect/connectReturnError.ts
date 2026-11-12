import { useSyncExternalStore } from '@wordpress/element';

const listeners = new Set<VoidFunction>();
const subscribe = (listener: VoidFunction): VoidFunction =>
{
    listeners.add(listener);
    return () => { listeners.delete(listener); };
};
const getMessage = (): string =>
{
    const message = Reflect.get(window.TranslateWP ?? {}, 'connectReturnError');
    return typeof message === 'string' ? message : '';
};

export const useConnectReturnError = (): string => useSyncExternalStore(subscribe, getMessage, () => '');

export const clearConnectReturnError = (): void =>
{
    if (window.TranslateWP) Reflect.set(window.TranslateWP, 'connectReturnError', '');
    for (const listener of listeners) listener();
};
