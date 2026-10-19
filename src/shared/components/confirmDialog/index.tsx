import { Modal, Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export interface ConfirmDialogContent
{
    title: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    isDestructive?: boolean;
    // A go-ahead the owner is invited to take (a translation run, a purchase) rather than warned off.
    tone?: 'default' | 'proceed';
}

export interface ConfirmDialogProps extends ConfirmDialogContent
{
    isOpen: boolean;
    onConfirm: VoidFunction;
    onCancel: VoidFunction;
}

export const ConfirmDialog = ({
    isOpen,
    title,
    message,
    confirmLabel,
    cancelLabel,
    isDestructive = false,
    tone = 'default',
    onConfirm,
    onCancel,
}: ConfirmDialogProps) =>
{
    if (!isOpen)
    {
        return null;
    }

    return (
        <Modal
            title={title}
            onRequestClose={onCancel}
            className="w-[420px]! max-w-[92vw]! rounded-xl!"
        >
            <p className="text-sm text-translatewp-neutral! mt-0! mb-6!">{message}</p>

            <div className="flex justify-end gap-2">
                <Button variant="tertiary" className={tone === 'proceed' ? 'text-translatewp-muted!' : undefined} onClick={onCancel}>
                    {cancelLabel ?? (tone === 'proceed' ? __('Maybe later', 'translate') : __('Cancel', 'translate'))}
                </Button>
                <Button
                    variant="primary"
                    isDestructive={isDestructive}
                    className={tone === 'proceed' ? 'rounded-sm! bg-translatewp-success! border-translatewp-success! text-white! hover:opacity-90' : undefined}
                    onClick={onConfirm}
                    data-confirm-tone={tone}
                >
                    {confirmLabel ?? __('Confirm', 'translate')}
                </Button>
            </div>
        </Modal>
    );
};
