export type JobEventKind =
    | 'quota_block'
    | 'provider_error'
    | 'timeout'
    | 'cancelled'
    | 'state_persistence_error';

export type JobEvent = {
    id: string;
    siteId: string;
    accountId: string;
    kind: JobEventKind | 'unknown';
    occurredAt: string;
    // Sent by the service since the reason codes landed; absent from older proxies.
    reasonCode?: string | null;
    message?: string | null;
    pageUrl?: string | null;
    targetLang?: string | null;
    resolved?: boolean;
};

export type JobEventsResponse = {
    events: JobEvent[];
};

export type FeedbackResponse = {
    id: string;
    success: boolean;
};

const KNOWN_KINDS: JobEventKind[] = [
    'quota_block',
    'provider_error',
    'timeout',
    'cancelled',
    'state_persistence_error',
];

const EVENT_LABELS: Record<JobEventKind | 'unknown', string> = {
    quota_block: 'Quota limit reached',
    provider_error: 'Translation provider error',
    timeout: 'Translation timed out',
    cancelled: 'Translation cancelled',
    state_persistence_error: 'Translation state could not be saved',
    unknown: 'Translation event',
};

export const normalizeJobEventKind = (kind: string): JobEventKind | 'unknown' =>
{
    return KNOWN_KINDS.includes(kind as JobEventKind) ? kind as JobEventKind : 'unknown';
};

export const formatJobEventTime = (occurredAt: string): string | null =>
{
    const timestamp = Date.parse(occurredAt);
    if (Number.isNaN(timestamp)) {
        return null;
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    }).format(new Date(timestamp));
};

export const eventKindLabel = (kind: JobEventKind | 'unknown'): string =>
    EVENT_LABELS[kind] ?? EVENT_LABELS.unknown;
