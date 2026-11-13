import { mergeSuggestions } from '@/apps/admin/components/Lexicon/Candidates/suggestions';
import type { LexiconCandidate } from '@/shared/lib/lexiconCandidates';

const candidate = (overrides: Partial<LexiconCandidate> = {}): LexiconCandidate => ({
    id: 'review-beta', sourceLocale: 'en', normalizedSource: 'beta', sourceText: 'Beta',
    languages: { de: { rendering: 'Beta', proposed: 'Beta', status: 'new' } },
    confidence: 0.95, evidence: [{ url: 'https://example.test/pricing', sentence: 'Join the Beta.' }],
    reason: 'wrong', status: 'new', scanId: 'scan-1', updatedAt: '', ...overrides,
});

it('shows a case-insensitive duplicate once using the richer row and both source references', () =>
{
    const rich = candidate();
    const rows = mergeSuggestions([
        { term: ' beta ', description: 'Keep the brand', category: 'Brand' },
        { term: 'Tidewell', description: 'Keep the name', category: 'Brand' },
    ], [rich]);
    expect(rows).toHaveLength(2);
    expect(rows[0]).toMatchObject({
        sourceText: 'Beta', reason: rich.reason, confidence: rich.confidence,
        evidence: rich.evidence, languages: rich.languages, siteTerms: [' beta '],
        candidateIds: [rich.id], siteOnly: false,
    });
    expect(rows[1].sourceText).toBe('Tidewell');
    expect(Number.isNaN(rows[1].confidence)).toBe(true);
    expect(rows[1].evidence).toEqual([]);
});

it('retains the site description when the richer review has no reason', () =>
{
    expect(mergeSuggestions([{ term: 'beta', description: 'Saved brand instruction', category: 'Brand' }], [candidate({ reason: '' })])[0])
        .toMatchObject({ sourceText: 'Beta', reason: 'Saved brand instruction' });
});

it('keeps the evidence-rich review regardless of which duplicate arrives first', () =>
{
    const rich = candidate();
    const sparse = candidate({ id: 'sparse', sourceText: 'BETA', evidence: [], reason: 'Sparse reason' });
    for (const reviews of [[sparse, rich], [rich, sparse]])
    {
        const rows = mergeSuggestions([], reviews);
        expect(rows).toHaveLength(1);
        expect(rows[0]).toMatchObject({ sourceText: 'Beta', reason: rich.reason, evidence: rich.evidence });
        expect(rows[0].candidateIds?.sort()).toEqual(['review-beta', 'sparse']);
    }
});

it('retains every site reference while keeping different names separate', () =>
{
    const rows = mergeSuggestions([
        { term: 'Beta', description: 'Brand', category: 'Brand' },
        { term: 'BETA', description: 'Brand', category: 'Brand' },
        { term: 'Beta Testing', description: 'Program', category: 'Program' },
    ], []);
    expect(rows.map(row => row.sourceText)).toEqual(['Beta', 'Beta Testing']);
    expect(rows[0].siteTerms).toEqual(['Beta', 'BETA']);
});
