import { create } from 'zustand';

import type { ConfirmDialogContent } from '@/shared/components/confirmDialog';

export type RequestConfirmation = (content: ConfirmDialogContent) => Promise<boolean>;

interface ConfirmStore {
    request: RequestConfirmation | null;
    setRequest: (request: RequestConfirmation | null) => void;
}

/**
 * The wizard shell owns the one confirm dialog; steps and hooks ask it for a
 * decision through this store instead of each rendering their own dialog.
 */
export const useConfirmStore = create<ConfirmStore>()((set) => ({
    request: null,
    setRequest: (request) => set({ request }),
}));
