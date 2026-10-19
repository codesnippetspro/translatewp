import type { TranslateWPAiSettings, TranslateWPLexiconTerm } from '@/shared/types';
import type { TranslationFingerprintSettings } from '@/shared/lib/translations/fingerprintPayload';

/**
 * The translation settings for one target language: lexicon terms scoped to
 * that language or to no language, reduced to the two fields the service
 * reads. The server reduces the same way, so both sides fingerprint the same
 * settings.
 */
export const aiSettingsForLanguage = (settings: TranslateWPAiSettings, targetLang: string): TranslationFingerprintSettings =>
{
    const terms = Array.isArray(settings.lexicon_terms) ? settings.lexicon_terms : [];
    const scoped = terms
        .filter((term: TranslateWPLexiconTerm) =>
        {
            const languages = Array.isArray(term.languages) ? term.languages : [];
            return !targetLang || languages.length === 0 || languages.includes(targetLang);
        })
        .map((term) => ({ term: term.term, description: term.description ?? '' }));

    return { ...settings, lexicon_terms: scoped };
};
