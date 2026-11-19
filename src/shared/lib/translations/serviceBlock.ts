// The violation event and the failed request are queued separately, so a
// failed lookup waits this long for the event before it counts as a plain
// network error.
const VIOLATION_GRACE_MS = 100;

export const SERVICE_BLOCKED_ERROR = 'service_blocked';

let serviceOrigin = '';
let blocked = false;
const waiters = new Set<VoidFunction>();
let onBlocked: ((origin: string) => void) | undefined;

const BLOCKING_DIRECTIVES = ['connect-src', 'default-src'];

// An opaque origin ('null') never names the service.
const originOf = (url: string): string =>
{
    try
    {
        const origin = new URL(url).origin;
        return origin === 'null' ? '' : origin;
    }
    catch
    {
        return '';
    }
};

function handleViolation(event: Event): void
{
    const violation = event as SecurityPolicyViolationEvent;
    const directive = violation.effectiveDirective || violation.violatedDirective || '';
    // Only an enforced policy stops the request; a report-only one lets it through.
    if (blocked || '' === serviceOrigin || violation.disposition !== 'enforce'
        || !BLOCKING_DIRECTIVES.some(name => directive.startsWith(name))
        || originOf(violation.blockedURI) !== serviceOrigin) return;
    blocked = true;
    for (const resolve of [...waiters]) resolve();
    onBlocked?.(serviceOrigin);
}

/**
 * Watches for a Content-Security-Policy that blocks requests to the service.
 * The first violation naming the service origin marks it blocked for this
 * page view and calls onBlocked once. Returns the function that stops watching.
 */
export function watchServiceBlock(serviceBaseUrl: string, blockedCallback?: (origin: string) => void): VoidFunction
{
    serviceOrigin = originOf(serviceBaseUrl);
    blocked = false;
    onBlocked = blockedCallback;
    document.addEventListener('securitypolicyviolation', handleViolation);

    return () =>
    {
        document.removeEventListener('securitypolicyviolation', handleViolation);
        serviceOrigin = '';
        blocked = false;
        onBlocked = undefined;
        for (const resolve of [...waiters]) resolve();
    };
}

export const isServiceBlocked = (): boolean => blocked;

/** Whether a failed request to the service failed because the page policy blocked it. */
export function blockedByPolicy(error: unknown): Promise<boolean>
{
    if (blocked) return Promise.resolve(true);
    if (!(error instanceof TypeError) || '' === serviceOrigin) return Promise.resolve(false);

    return new Promise(resolve =>
    {
        const settle = (): void =>
        {
            clearTimeout(timer);
            waiters.delete(settle);
            resolve(blocked);
        };
        const timer = setTimeout(settle, VIOLATION_GRACE_MS);
        waiters.add(settle);
    });
}
