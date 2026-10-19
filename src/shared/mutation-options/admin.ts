import {
    submitPluginFeedback,
    applyLexiconNow,
    applyLocalizerNow,
    retranslatePage,
    retranslateEverything,
    openSupportTicket,
} from '@/shared/api/admin';
import type { RetranslatePageInput } from '@/shared/api/admin';

export const submitPluginFeedbackMutationOptions = () => ({
    mutationFn: (message: string) => submitPluginFeedback(message),
});

export const openSupportTicketMutationOptions = () => ({
    mutationFn: ({ name, message, email }: { name: string; message: string; email: string }) => openSupportTicket(name, message, email),
});

export const applyLexiconNowMutationOptions = () => ({
    mutationFn: () => applyLexiconNow(),
});

export const applyLocalizerNowMutationOptions = () => ({
    mutationFn: () => applyLocalizerNow(),
});

export const retranslatePageMutationOptions = () => ({
    mutationFn: (input: RetranslatePageInput) => retranslatePage(input),
    // Same-origin REST call: 'online' mode would pause it when navigator.onLine
    // misreports offline.
    networkMode: 'always' as const,
});

export const retranslateEverythingMutationOptions = () => ({
    mutationFn: (confirm: string) => retranslateEverything(confirm),
    networkMode: 'always' as const,
});