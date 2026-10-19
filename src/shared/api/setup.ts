import apiFetch from '@wordpress/api-fetch';

export type ScanContextResponse = {
    context: string;
    language?: string;
    style: 'marketing' | 'neutral' | 'medical' | 'technical' | 'legal';
    tone: 'friendly' | 'casual' | 'professional' | 'formal';
    formality: number;
    creativity: number;
};

export type ScanLexiconSuggestion = { term: string; description: string; category: string };

export type ScanContextStatusResponse = {
    status: 'pending' | 'done' | 'failed';
    /** While pending: which part of the scan runs and how far it is. */
    phase?: 'collecting' | 'analysing';
    sampled?: number;
    total?: number;
    elapsedSeconds?: number;
    /** False once the site holds translations: the result only fills the form. */
    applied?: boolean;
    /** Terms the scan proposes to a Pro site; a Free site gets none. They land only through applyScanLexicon. */
    lexiconSuggestions?: ScanLexiconSuggestion[];
    context?: string;
    language?: string;
    style?: ScanContextResponse['style'];
    tone?: ScanContextResponse['tone'];
    formality?: number;
    creativity?: number;
    reason?: string;
};

export const scanContext = async (): Promise<{ status: string }> =>
{
    return await apiFetch<{ status: string }>({
        path: 'translatewp/v1/scanning/context',
        method: 'POST',
        data: {}
    });
}

export const applyScanLexicon = async (): Promise<{ added: number }> =>
{
    return await apiFetch<{ added: number }>({
        path: 'translatewp/v1/scanning/context/apply-lexicon',
        method: 'POST',
        data: {},
    });
}

export const getScanContextStatus = async (): Promise<ScanContextStatusResponse> =>
{
    return await apiFetch<ScanContextStatusResponse>({
        path: 'translatewp/v1/scanning/context/status',
        method: 'GET',
    });
}

export const finishSetup = async (defaultLanguage: string, languages: string[], autoTranslate: boolean, context: string) =>
{
    return await apiFetch({
        path: 'translatewp/v1/management/finish-setup',
        method: 'POST',
        data: {
            default_language: defaultLanguage,
            languages:        languages,
            auto_translate:   autoTranslate,
            context:          context,
        }
    });
}

export type SaveStepInput = {
    step: 'languages' | 'translation' | 'context' | 'read-site' | 'style-tone';
    resumeStep?: string;
    defaultLanguage?: string;
    languages?: string[];
    autoTranslate?: boolean;
    context?: string;
    style?: string;
    tone?: string;
};

export const saveSetupStep = async (input: SaveStepInput) =>
{
    return await apiFetch<{ success: boolean; step: string }>({
        path: 'translatewp/v1/management/save-step',
        method: 'POST',
        data: {
            step:             input.step,
            resume_step:      input.resumeStep,
            default_language: input.defaultLanguage,
            languages:        input.languages,
            auto_translate:   input.autoTranslate,
            context:          input.context,
            style:            input.style,
            tone:             input.tone,
        }
    });
}
