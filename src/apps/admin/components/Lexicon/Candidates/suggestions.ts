import type { LexiconCandidate } from '@/shared/lib/lexiconCandidates';
import type { ScanLexiconSuggestion } from '@/shared/api/setup';

export type ReviewSuggestion = LexiconCandidate & {
    siteTerms?: string[];
    candidateIds?: string[];
    siteOnly?: boolean;
};

export const mergeSuggestions = (site: ScanLexiconSuggestion[], candidates: LexiconCandidate[]): ReviewSuggestion[] =>
{
    const rows = new Map<string, ReviewSuggestion>();
    const key = (term: string) => term.trim().toLowerCase();
    for (const proposal of site)
    {
        const normalized = key(proposal.term);
        if (!normalized) continue;
        const previous = rows.get(normalized);
        rows.set(normalized, {
            id: `site:${normalized}`, sourceText: proposal.term, normalizedSource: normalized,
            sourceLocale: '', languages: {}, confidence: Number.NaN, evidence: [],
            reason: proposal.description, status: 'new', scanId: null, updatedAt: '',
            ...previous, siteOnly: true, siteTerms: [...previous?.siteTerms ?? [], proposal.term],
        });
    }
    const richness = (candidate: LexiconCandidate) => candidate.evidence.length * 2
        + Object.values(candidate.languages).filter(language => language.proposed).length;
    for (const candidate of candidates)
    {
        const normalized = key(candidate.sourceText);
        const previous = rows.get(normalized);
        const preferred = !previous || previous.siteOnly || richness(candidate) > richness(previous) ? candidate : previous;
        rows.set(normalized, {
            ...preferred, reason: preferred.reason.trim() || previous?.reason || '',
            siteOnly: false, siteTerms: previous?.siteTerms ?? [],
            candidateIds: [...previous?.candidateIds ?? [], candidate.id],
        });
    }
    return [...rows.values()];
};
