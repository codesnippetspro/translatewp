/**
 * The Overview shows one clear next action for the state the site is
 * in, so its tiles never sit at zero without saying why. This pure resolver
 * maps the data the Overview already holds to a single next action; the header
 * renders the matching primary and secondary controls. Connection and address
 * states defer to the ConnectionPanel and AddressChangedNotice in the body.
 */

export type OverviewLanguageStatus = {
    locale: string;
    /** Page translations whose job finished. */
    ready: number;
    /** Every page translation of the language, failed ones included. */
    readyTotal: number;
    /** Nothing in flight: translated plus failed covers the total. */
    readyComplete: boolean;
    total: number;
    dead: number;
    repairing: number;
    retranslating: number;
};

export type OverviewActionInput = {
    connected: boolean;
    connectionStatus?: 'connected' | 'invalidated' | 'disconnected';
    addressChanged: boolean;
    statusFailed: boolean;
    quotaPaused: boolean;
    scheduledTasksLate: boolean;
    hasTargetLanguage: boolean;
    readPages: number;
    changedPages: number;
    staleCount: number;
    firstPassDone: boolean;
    languages: OverviewLanguageStatus[];
};

export type OverviewAction =
    | { kind: 'connection' }
    | { kind: 'retry' }
    | { kind: 'quota' }
    | { kind: 'addLanguage' }
    | { kind: 'read' }
    | { kind: 'repairing'; count: number }
    | { kind: 'retranslating'; locales: string[]; done: number; total: number }
    | { kind: 'readiness' }
    | { kind: 'translating'; count: number }
    | { kind: 'failed'; count: number }
    | { kind: 'translateLanguage'; locale: string }
    | { kind: 'rereadChanged'; count: number }
    | { kind: 'retranslateStale'; count: number }
    | { kind: 'sync' }
    | { kind: 'preview'; locales: string[] };

const sum = (languages: OverviewLanguageStatus[], pick: (language: OverviewLanguageStatus) => number): number =>
    languages.reduce((total, language) => total + pick(language), 0);

export function resolveOverviewAction(input: OverviewActionInput): OverviewAction
{
    if (!input.connected || input.connectionStatus === 'invalidated' || input.addressChanged) {
        return { kind: 'connection' };
    }

    if (input.statusFailed) {
        return { kind: 'retry' };
    }

    if (input.quotaPaused) {
        return { kind: 'quota' };
    }

    if (!input.hasTargetLanguage) {
        return { kind: 'addLanguage' };
    }

    if (input.readPages === 0) {
        return { kind: 'read' };
    }

    const repairing = sum(input.languages, language => language.repairing);
    if (repairing > 0) {
        return { kind: 'repairing', count: repairing };
    }

    const retranslated = input.languages.filter(language => language.retranslating > 0);
    if (retranslated.length > 0) {
        return {
            kind: 'retranslating',
            locales: retranslated.map(language => language.locale),
            done: sum(retranslated, language => language.ready),
            total: sum(retranslated, language => language.readyTotal),
        };
    }

    const inFlight = input.languages.filter(language => language.total > 0 && !language.readyComplete);
    if (inFlight.length > 0) {
        if (!input.firstPassDone) {
            return { kind: 'readiness' };
        }
        return { kind: 'translating', count: sum(inFlight, language => language.readyTotal - language.ready - language.dead) };
    }

    const newLanguage = input.languages.find(language => language.total === 0);
    if (newLanguage) {
        return { kind: 'translateLanguage', locale: newLanguage.locale };
    }

    const failed = sum(input.languages, language => language.dead);
    if (failed > 0) {
        return { kind: 'failed', count: failed };
    }

    if (input.changedPages > 0) {
        return { kind: 'rereadChanged', count: input.changedPages };
    }

    if (input.staleCount > 0) {
        return { kind: 'retranslateStale', count: input.staleCount };
    }

    if (input.scheduledTasksLate) {
        return { kind: 'sync' };
    }

    return { kind: 'preview', locales: input.languages.map(language => language.locale) };
}
