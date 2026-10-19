type VolatilityRecord = {
    values: string[];
    novelAt: number[];
    strikesAt: number[];
    volatileSince: number | null;
    lastSeen: number;
    lastNovelAt: number;
};

export type VolatilityTrackerOptions = {
    now?: () => number;
    threshold?: number;
    windowMs?: number;
    quietMs?: number;
};

/**
 * Tracks, per node, how many never-seen values it has taken inside a sliding
 * window. A node stays marked volatile until no never-seen value has arrived
 * for `quietMs`; values it has shown before never count. A text node is keyed
 * by its parent element.
 */
export class VolatilityTracker
{
    static readonly DEFAULT_THRESHOLD = 12;
    static readonly DEFAULT_WINDOW_MS = 60_000;
    static readonly DEFAULT_QUIET_MS = 60_000;
    static readonly STRIKES = 3;
    static readonly KEEP_VALUES = 64;
    static readonly MAX_TRACKED = 500;

    #records = new Map<Node, VolatilityRecord>();
    #now: () => number;
    #threshold: number;
    #windowMs: number;
    #quietMs: number;

    constructor({
        now = () => Date.now(),
        threshold = VolatilityTracker.DEFAULT_THRESHOLD,
        windowMs = VolatilityTracker.DEFAULT_WINDOW_MS,
        quietMs = VolatilityTracker.DEFAULT_QUIET_MS,
    }: VolatilityTrackerOptions = {})
    {
        this.#now = now;
        this.#threshold = threshold;
        this.#windowMs = windowMs;
        this.#quietMs = quietMs;
    }

    /**
     * Records the value a node just changed to. Returns true when the node is
     * volatile and this change must be left alone.
     */
    record(node: Node, value: string): boolean
    {
        const now = this.#now();
        const record = this.#recordFor(node, now);

        record.lastSeen = now;
        const novel = !record.values.includes(value);
        const quietFor = now - record.lastNovelAt;
        if (novel)
        {
            record.values.push(value);
            if (record.values.length > VolatilityTracker.KEEP_VALUES) record.values.shift();
            record.lastNovelAt = now;
        }

        if (record.volatileSince !== null)
        {
            if (quietFor < this.#quietMs) return true;

            record.volatileSince = null;
            record.novelAt = [];
        }

        if (!novel) return false;

        record.novelAt = record.novelAt.filter(at => now - at < this.#windowMs);
        record.novelAt.push(now);

        if (record.novelAt.length >= this.#threshold)
        {
            record.volatileSince = now;
            return true;
        }

        return false;
    }

    /**
     * Records that the page wrote a node back to the source text the runtime
     * had just translated. Returns true when the node is volatile: enough
     * strikes inside the window, or still volatile from before.
     */
    strike(node: Node): boolean
    {
        const now = this.#now();
        const record = this.#recordFor(node, now);
        record.lastSeen = now;

        if (record.volatileSince !== null && now - record.lastNovelAt < this.#quietMs) return true;

        record.strikesAt = record.strikesAt.filter(at => now - at < this.#windowMs);
        record.strikesAt.push(now);
        if (record.strikesAt.length < VolatilityTracker.STRIKES) return false;

        record.strikesAt = [];
        record.volatileSince = now;
        record.lastNovelAt = now;
        return true;
    }

    isVolatile(node: Node): boolean
    {
        const record = this.#records.get(VolatilityTracker.#keyFor(node));
        return !!record
            && record.volatileSince !== null
            && this.#now() - record.lastNovelAt < this.#quietMs;
    }

    #recordFor(node: Node, now: number): VolatilityRecord
    {
        const key = VolatilityTracker.#keyFor(node);
        const existing = this.#records.get(key);
        if (existing) return existing;

        const record = { values: [], novelAt: [], strikesAt: [], volatileSince: null, lastSeen: now, lastNovelAt: now };
        this.#records.set(key, record);
        this.#evictQuietest();
        return record;
    }

    static #keyFor(node: Node): Node
    {
        return node instanceof Element ? node : (node.parentElement ?? node);
    }

    #evictQuietest(): void
    {
        if (this.#records.size <= VolatilityTracker.MAX_TRACKED) return;

        let quietest: [Node, VolatilityRecord] | null = null;
        for (const entry of this.#records)
        {
            if (!quietest || entry[1].lastSeen < quietest[1].lastSeen) quietest = entry;
        }
        if (quietest) this.#records.delete(quietest[0]);
    }
}
