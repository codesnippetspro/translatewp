import type { TranslatableEntry } from '@/shared/lib/translations/TranslatableEntry';

export const isTextNodeInViewport = (node: Node): boolean =>
{
    if (node.nodeType !== Node.TEXT_NODE || !node.isConnected) return false;
    for (let element = node.parentElement; element; element = element.parentElement)
    {
        const style = getComputedStyle(element);
        if (style.display === 'none' || style.visibility === 'hidden'
            || style.visibility === 'collapse' || style.opacity === '0') return false;
    }
    const range = document.createRange();
    if (typeof range.getClientRects !== 'function') return false;
    range.selectNodeContents(node);
    return Array.from(range.getClientRects()).some(rect => rect.width > 0 && rect.height > 0
        && rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight && rect.left < window.innerWidth);
};

export const observePendingTextEntries = (entries: TranslatableEntry[], onChange: (pending: number) => void) =>
{
    const pending = new Map(entries.map(entry => [entry.id, entry.node]));
    let lastCount = -1;
    let frame: number | undefined;
    let disconnected = false;
    const report = () =>
    {
        frame = undefined;
        if (disconnected) return;
        let count = 0;
        for (const node of pending.values()) if (isTextNodeInViewport(node)) count += 1;
        if (count !== lastCount)
        {
            lastCount = count;
            onChange(count);
        }
    };
    const scheduleReport = () =>
    {
        if (frame === undefined && !disconnected) frame = window.requestAnimationFrame(report);
    };
    const observer = typeof IntersectionObserver === 'undefined' ? null : new IntersectionObserver(scheduleReport);
    for (const element of new Set(entries.map(entry => entry.node.parentElement)))
        if (element) observer?.observe(element);
    window.addEventListener('scroll', scheduleReport, true);
    window.addEventListener('resize', scheduleReport);
    report();
    return {
        settle: (entry: TranslatableEntry) =>
        {
            if (pending.delete(entry.id)) scheduleReport();
        },
        disconnect: () =>
        {
            disconnected = true;
            observer?.disconnect();
            window.removeEventListener('scroll', scheduleReport, true);
            window.removeEventListener('resize', scheduleReport);
            if (frame !== undefined) window.cancelAnimationFrame(frame);
            pending.clear();
        },
    };
};
