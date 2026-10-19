export const TRANSLATION_PUSH_BATCH_MAX_ITEMS = 24;
export const TRANSLATION_PUSH_BATCH_MAX_BODY_BYTES = 480_000;
export const TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES = 16_384;

export type PushBatchItemEstimateInput = {
    groupId: string;
    markerPayload: string;
    contextKey: string;
    contextKind: string;
    neighbours?: {
        before: string;
        after: string;
    };
    cache: {
        siteId: string;
        hash: string;
    };
};

export function estimatePushBatchItemBytes(item: PushBatchItemEstimateInput): number
{
    return new TextEncoder().encode(JSON.stringify(item)).length;
}

export function estimatePushBatchBodyBytes(
    items: PushBatchItemEstimateInput[],
    closeInput: boolean,
    jobId: string,
    batchId: string
): number
{
    return new TextEncoder().encode(JSON.stringify({
        jobId,
        batchId,
        closeInput,
        items,
    })).length;
}

export function chunkGroupsForPushBatch<T>(
    groups: T[],
    estimateItem: (group: T) => PushBatchItemEstimateInput,
    jobId: string,
    batchIdForIndex: (batchIndex: number) => string
): T[][]
{
    const chunks: T[][] = [];
    let currentChunk: T[] = [];
    let batchIndex = 0;

    const flush = (): void =>
    {
        if (!currentChunk.length)
        {
            return;
        }

        chunks.push(currentChunk);
        currentChunk = [];
        batchIndex++;
    };

    for (const group of groups)
    {
        const candidateItems = [
            ...currentChunk.map(estimateItem),
            estimateItem(group),
        ];
        const exceedsCount = candidateItems.length > TRANSLATION_PUSH_BATCH_MAX_ITEMS;
        const exceedsBytes = estimatePushBatchBodyBytes(
            candidateItems,
            false,
            jobId,
            batchIdForIndex(batchIndex)
        ) > TRANSLATION_PUSH_BATCH_MAX_BODY_BYTES;

        if (currentChunk.length && (exceedsCount || exceedsBytes))
        {
            flush();
        }

        currentChunk.push(group);
    }

    flush();

    return chunks;
}
