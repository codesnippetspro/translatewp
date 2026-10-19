import { useCallback, useState } from '@wordpress/element';

import type { ConfirmDialogContent, ConfirmDialogProps } from '@/shared/components/confirmDialog';

interface PendingConfirmation
{
    content: ConfirmDialogContent;
    resolve: (confirmed: boolean) => void;
}

const EMPTY_CONTENT: ConfirmDialogContent = { title: '', message: '' };

export const useConfirmDialog = () =>
{
    const [pending, setPending] = useState<PendingConfirmation | null>(null);

    const requestConfirmation = useCallback(
        (content: ConfirmDialogContent) =>
            new Promise<boolean>((resolve) =>
            {
                setPending((current) =>
                {
                    // One dialog at a time: a request made while another is
                    // already open is declined rather than stacking a second
                    // dialog over the first.
                    if (current)
                    {
                        resolve(false);

                        return current;
                    }

                    return { content, resolve };
                });
            }),
        []
    );

    const settle = useCallback(
        (confirmed: boolean) =>
            setPending((current) =>
            {
                current?.resolve(confirmed);

                return null;
            }),
        []
    );

    const confirmDialogProps: ConfirmDialogProps = {
        ...(pending?.content ?? EMPTY_CONTENT),
        isOpen: pending !== null,
        onConfirm: () => settle(true),
        onCancel: () => settle(false),
    };

    return { requestConfirmation, confirmDialogProps };
};
