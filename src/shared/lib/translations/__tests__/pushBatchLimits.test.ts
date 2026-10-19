import { TextEncoder } from 'util';

import {
    TRANSLATION_PUSH_BATCH_MAX_BODY_BYTES,
    TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES,
    chunkGroupsForPushBatch,
    estimatePushBatchBodyBytes,
    estimatePushBatchItemBytes,
    type PushBatchItemEstimateInput,
} from '@/shared/lib/translations/pushBatchLimits';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

function createItem(id: string, markerPayload: string): PushBatchItemEstimateInput
{
    return {
        groupId: id,
        markerPayload,
        contextKey: 'main > p',
        contextKind: 'text-flow',
        cache: {
            siteId: 'site-1',
            hash: `hash-${id}`,
        },
    };
}

describe('chunkGroupsForPushBatch', () =>
{
    it('splits batches when the serialized body exceeds the byte ceiling', () =>
    {
        const markerPayload = '⟪item_0001⟫' + 'x'.repeat(250_000);
        const groups = [
            createItem('grp-1', markerPayload),
            createItem('grp-2', markerPayload),
        ];

        const chunks = chunkGroupsForPushBatch(
            groups,
            (group) => group,
            'job-1',
            (batchIndex) => `batch-${batchIndex}`
        );

        expect(chunks).toHaveLength(2);
        expect(chunks[0]).toHaveLength(1);
        expect(chunks[1]).toHaveLength(1);
        expect(
            estimatePushBatchBodyBytes(chunks[0], false, 'job-1', 'batch-0')
        ).toBeLessThanOrEqual(TRANSLATION_PUSH_BATCH_MAX_BODY_BYTES);
        expect(
            estimatePushBatchBodyBytes(chunks[1], false, 'job-1', 'batch-1')
        ).toBeLessThanOrEqual(TRANSLATION_PUSH_BATCH_MAX_BODY_BYTES);
    });

    it('counts UTF-8 bytes when estimating marker payload size', () =>
    {
        const utf8Marker = '⟪item_0001⟫' + '🙂'.repeat(8_000);
        const bytes = estimatePushBatchItemBytes(createItem('grp-utf8', utf8Marker));

        expect(bytes).toBeGreaterThan(TRANSLATION_PUSH_BATCH_MAX_MARKER_PAYLOAD_BYTES);
        expect(bytes).toBe(new TextEncoder().encode(JSON.stringify({
            groupId: 'grp-utf8',
            markerPayload: utf8Marker,
            contextKey: 'main > p',
            contextKind: 'text-flow',
            cache: {
                siteId: 'site-1',
                hash: 'hash-grp-utf8',
            },
        })).length);
    });
});
