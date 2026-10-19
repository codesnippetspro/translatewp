import { digitRuns, normalizeNumbers, substituteNumbers } from '@/shared/lib/translations/numericText';

type RememberedTranslation = {
    source: string;
    translated: string;
};

/**
 * Remembers, per target language, how a text whose numbers vary was translated,
 * and re-translates a later value of the same text locally by writing its
 * digits into the remembered translation. A translation is remembered only when
 * it carries the source's digit runs verbatim and in order.
 */
export class NumericTemplateMemory
{
    static readonly MAX_TEMPLATES_PER_LANGUAGE = 256;

    #byLanguage = new Map<string, Map<string, RememberedTranslation>>();
    #pending = new Map<string, Set<string>>();

    markPending(targetLang: string, source: string): void
    {
        const templates = this.#pending.get(targetLang) ?? new Set<string>();
        templates.add(normalizeNumbers(source));
        this.#pending.set(targetLang, templates);
    }

    clearPending(targetLang: string, source: string): void
    {
        this.#pending.get(targetLang)?.delete(normalizeNumbers(source));
    }

    isPending(targetLang: string, value: string): boolean
    {
        return digitRuns(value).length > 0 && (this.#pending.get(targetLang)?.has(normalizeNumbers(value)) ?? false);
    }

    remember(targetLang: string, source: string, translated: string): boolean
    {
        const runs = digitRuns(source);
        if (runs.length === 0) return false;
        if (digitRuns(translated).join(',') !== runs.join(',')) return false;

        const templates = this.#byLanguage.get(targetLang) ?? new Map<string, RememberedTranslation>();
        const template = normalizeNumbers(source);
        templates.delete(template);
        templates.set(template, { source, translated });
        if (templates.size > NumericTemplateMemory.MAX_TEMPLATES_PER_LANGUAGE)
        {
            const oldest = templates.keys().next().value;
            if (oldest !== undefined) templates.delete(oldest);
        }
        this.#byLanguage.set(targetLang, templates);
        return true;
    }

    resolve(targetLang: string, value: string): string | null
    {
        if (digitRuns(value).length === 0) return null;

        const remembered = this.#byLanguage.get(targetLang)?.get(normalizeNumbers(value));
        if (!remembered) return null;

        return substituteNumbers(value, remembered.translated);
    }
}
