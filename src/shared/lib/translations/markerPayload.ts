import { MARKER_PREFIX, MARKER_SUFFIX } from '@/shared/lib/translations/ContextGrouping';
import { hasTranslatableWhitespaceText } from '@/shared/lib/translations/translationWhitespace';

export type MarkerPayloadValidationResult =
    | { valid: true }
    | { valid: false; message: string };

export function hasTranslatableText(value: string): boolean
{
    return hasTranslatableWhitespaceText(value);
}

export function validateMarkerPayload(markerPayload: string): MarkerPayloadValidationResult
{
    if (!markerPayload)
    {
        return {
            valid: false,
            message: 'Translation group must contain marker-prefixed entries.',
        };
    }

    const markerRegex = new RegExp(`${escapeRegExp(MARKER_PREFIX)}([^${escapeRegExp(MARKER_SUFFIX)}]+)${escapeRegExp(MARKER_SUFFIX)}`, 'g');
    const markers: { id: string; index: number; markerLength: number }[] = [];
    let match: RegExpExecArray | null;

    while ((match = markerRegex.exec(markerPayload)) !== null)
    {
        markers.push({
            id: match[1],
            index: match.index,
            markerLength: match[0].length,
        });
    }

    if (!markers.length || markers[0].index !== 0)
    {
        return {
            valid: false,
            message: 'Translation group must contain marker-prefixed entries.',
        };
    }

    const seenIds = new Set<string>();

    for (let index = 0; index < markers.length; index++)
    {
        const marker = markers[index];
        if (seenIds.has(marker.id))
        {
            return {
                valid: false,
                message: `Translation group contains duplicate ID "${marker.id}".`,
            };
        }
        seenIds.add(marker.id);

        const valueStart = marker.index + marker.markerLength;
        const valueEnd = index + 1 < markers.length ? markers[index + 1].index : markerPayload.length;
        const text = markerPayload.slice(valueStart, valueEnd);

        if (!hasTranslatableText(text))
        {
            return {
                valid: false,
                message: `Translation group entry "${marker.id}" is empty.`,
            };
        }
    }

    return { valid: true };
}

function escapeRegExp(value: string): string
{
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
