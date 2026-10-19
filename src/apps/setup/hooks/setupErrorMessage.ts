import { __ } from '@wordpress/i18n';

export const setupErrorMessage = (error: unknown): string =>
{
    const refusal = error as { code?: unknown; message?: unknown } | null;

    return refusal && typeof refusal.code === 'string' && typeof refusal.message === 'string' && refusal.message !== ''
        ? refusal.message
        : __('Your choices could not be saved. Try again.', 'translate');
};
