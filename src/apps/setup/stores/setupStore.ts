import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';

import { resolveCatalogLocale } from '@/shared/lib/languages';
import { setupDraftKey } from '@/shared/lib/setupDraft';
import type { IndexPageEntry } from '@/shared/api/admin';

interface SetupStore {
    spinePages: IndexPageEntry[];
    setSpinePages: (pages: IndexPageEntry[]) => void;
    defaultLanguage: string;
    languages: string[];
    autoTranslate: boolean;
    context: string;
    // The brief the last read proposed, so the next read replaces it rather than stacking.
    proposedContext: string;
    style: string;
    tone: string;
    isScanning: boolean;
    // The site has read rows on the server, or the Read your site
    // step finished in this wizard session. Never restored from the browser:
    // a reset or a restart on the same site starts unread.
    siteRead: boolean;
    setSiteRead: (siteRead: boolean) => void;
    // When the translation languages were last saved, in epoch milliseconds;
    // 0 while unknown. The homepage preview is timed from it.
    languagesSavedAt: number;
    setLanguagesSavedAt: (languagesSavedAt: number) => void;
    firstRunFinishedAt: number;
    setFirstRunFinishedAt: (firstRunFinishedAt: number) => void;
    setDefaultLanguage: (defaultLanguage: string) => void;
    setLanguages: (languages: string[]) => void;
    setAutoTranslate: (autoTranslate: boolean) => void;
    setContext: (context: string) => void;
    setProposedContext: (proposedContext: string) => void;
    setStyle: (style: string) => void;
    setTone: (tone: string) => void;
    setIsScanning: (isScanning: boolean) => void;
}

const setupDefaultLanguage = resolveCatalogLocale(window.setupData?.defaultLanguage ?? 'en_US') ?? 'en-us';
const setupLanguages = window.setupData?.languages ?? [];
const setupAutoTranslate = window.setupData?.autoTranslate ?? true;
const setupContext = window.setupData?.context ?? '';
const hasServerSetupData = Boolean(
    window.setupData && (
        window.setupData.languages?.length ||
        window.setupData.step ||
        window.setupData.context
    )
);

const storageKey = setupDraftKey();
export const setupStorageKey = (): string => storageKey;

export const useSetupStore = create<SetupStore>()(
    persist(
        (set) => ({
            spinePages: [],
            setSpinePages: spinePages => set({ spinePages }),
            defaultLanguage: setupDefaultLanguage,
            languages: setupLanguages,
            autoTranslate: setupAutoTranslate,
            context: setupContext,
            style: window.setupData?.style || 'neutral',
            tone: window.setupData?.tone || 'friendly',
            isScanning: false,
            siteRead: window.setupData?.siteRead ?? false,
            setSiteRead: (siteRead: boolean) => set({ siteRead }),
            languagesSavedAt: 0,
            setLanguagesSavedAt: (languagesSavedAt: number) => set({ languagesSavedAt, firstRunFinishedAt: 0 }),
            firstRunFinishedAt: 0,
            setFirstRunFinishedAt: (firstRunFinishedAt: number) => set({ firstRunFinishedAt }),
            // The previous default was the source, never a chosen translation
            // language, so a change of default drops it from the list.
            setDefaultLanguage: (defaultLanguage) => set(state =>
            {
                const next = resolveCatalogLocale(defaultLanguage) ?? state.defaultLanguage;
                return {
                    defaultLanguage: next,
                    languages: state.languages.filter(locale => locale !== next && locale !== state.defaultLanguage),
                };
            }),
            setLanguages: (languages) => set({ languages }),
            setAutoTranslate: (autoTranslate) => set({ autoTranslate }),
            setContext: (context: string) => set({ context }),
            proposedContext: '',
            setProposedContext: (proposedContext: string) => set({ proposedContext }),
            setStyle: (style: string) => set({ style }),
            setTone: (tone: string) => set({ tone }),
            setIsScanning: (isScanning: boolean) => set({ isScanning }),
        }),
        {
            name: storageKey,
            storage: createJSONStorage(() => localStorage),
            merge: (persistedState, currentState) =>
            {
                if (hasServerSetupData) {
                    return {
                        ...currentState,
                        languagesSavedAt: (persistedState as Partial<SetupStore>)?.languagesSavedAt ?? 0,
                        firstRunFinishedAt: (persistedState as Partial<SetupStore>)?.firstRunFinishedAt ?? 0,
                        isScanning: false,
                    };
                }

                const persisted = persistedState as Partial<SetupStore>;
                const defaultLanguage = resolveCatalogLocale(persisted.defaultLanguage ?? currentState.defaultLanguage)
                    ?? currentState.defaultLanguage;

                return {
                    ...currentState,
                    languages: persisted.languages ?? currentState.languages,
                    autoTranslate: persisted.autoTranslate ?? currentState.autoTranslate,
                    context: persisted.context ?? currentState.context,
                    proposedContext: persisted.proposedContext ?? currentState.proposedContext,
                    defaultLanguage,
                    languagesSavedAt: persisted.languagesSavedAt ?? 0,
                    isScanning: false,
                };
            },
            partialize: (state) => ({
                defaultLanguage: state.defaultLanguage,
                languages: state.languages,
                autoTranslate: state.autoTranslate,
                context: state.context,
                languagesSavedAt: state.languagesSavedAt,
            }),
        }
    )
);
