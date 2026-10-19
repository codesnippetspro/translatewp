export type CandidateStatus = 'new' | 'added' | 'skipped' | 'never';
export type CandidateAction = 'add' | 'skip' | 'never' | 'restore';
export type ScanState = 'queued' | 'running' | 'done' | 'failed';

export type LexiconScan = {
    id: string;
    state: ScanState;
    startedAt: string;
    finishedAt: string | null;
    progress: { stage?: string; records?: number; candidates?: number; judged?: number };
    summary: { records?: number; mined?: number; judged?: number; proposed?: number; fine?: number; error?: string };
};

export type LexiconCandidate = {
    id: string;
    sourceLocale: string;
    normalizedSource: string;
    sourceText: string;
    languages: Record<string, { rendering: string | null; proposed: string | null; status: CandidateStatus }>;
    confidence: number;
    evidence: Array<{ url: string; sentence: string }>;
    reason: string;
    status: CandidateStatus;
    scanId: string | null;
    updatedAt: string;
};

export const isScanOpen = (scan: LexiconScan | null | undefined): boolean => scan?.state === 'queued' || scan?.state === 'running';
