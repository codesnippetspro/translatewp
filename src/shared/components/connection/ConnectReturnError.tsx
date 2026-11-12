import { useConnectReturnError } from '@/shared/lib/connect/connectReturnError';

export const ConnectReturnError = () =>
{
    const message = useConnectReturnError();
    if (!message) return null;
    return (
        <p className="m-0 rounded-sm border border-solid border-translatewp-error bg-white p-4 text-sm text-translatewp-error" role="alert">
            {message}
        </p>
    );
};
