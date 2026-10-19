import { MARKER_PREFIX, MARKER_SUFFIX } from '@/shared/lib/translations/ContextGrouping';
import {
    hasTranslatableText,
    validateMarkerPayload,
} from '@/shared/lib/translations/markerPayload';

describe('markerPayload validation', () =>
{
    it('rejects empty payloads', () =>
    {
        expect(validateMarkerPayload('')).toEqual({
            valid: false,
            message: 'Translation group must contain marker-prefixed entries.',
        });
    });

    it('rejects payloads that do not start with a marker', () =>
    {
        expect(validateMarkerPayload(`Hello${MARKER_PREFIX}item_0001${MARKER_SUFFIX}World`)).toEqual({
            valid: false,
            message: 'Translation group must contain marker-prefixed entries.',
        });
    });

    it('rejects duplicate marker ids', () =>
    {
        const payload = `${MARKER_PREFIX}item_0001${MARKER_SUFFIX}Hello${MARKER_PREFIX}item_0001${MARKER_SUFFIX}World`;
        expect(validateMarkerPayload(payload)).toEqual({
            valid: false,
            message: 'Translation group contains duplicate ID "item_0001".',
        });
    });

    it('rejects whitespace-only entry text', () =>
    {
        const payload = `${MARKER_PREFIX}item_0001${MARKER_SUFFIX}Hello${MARKER_PREFIX}item_0002${MARKER_SUFFIX}   `;
        expect(validateMarkerPayload(payload)).toEqual({
            valid: false,
            message: 'Translation group entry "item_0002" is empty.',
        });
    });

    it('rejects NBSP-only entry text', () =>
    {
        const payload = `${MARKER_PREFIX}item_0001${MARKER_SUFFIX}Hello${MARKER_PREFIX}item_0002${MARKER_SUFFIX}\u00A0`;
        expect(validateMarkerPayload(payload)).toEqual({
            valid: false,
            message: 'Translation group entry "item_0002" is empty.',
        });
    });

    it('accepts valid marker payloads', () =>
    {
        const payload = `${MARKER_PREFIX}item_0001${MARKER_SUFFIX}Hello${MARKER_PREFIX}item_0002${MARKER_SUFFIX} world`;
        expect(validateMarkerPayload(payload)).toEqual({ valid: true });
    });

    it('accepts marker payloads with preserved newline content', () =>
    {
        const payload = `${MARKER_PREFIX}item_0001${MARKER_SUFFIX}Hello\nworld`;
        expect(validateMarkerPayload(payload)).toEqual({ valid: true });
    });
});

describe('hasTranslatableText', () =>
{
    it('treats separator whitespace-only strings as empty', () =>
    {
        expect(hasTranslatableText('   ')).toBe(false);
        expect(hasTranslatableText('\u00A0')).toBe(false);
        expect(hasTranslatableText('Hello')).toBe(true);
    });

    it('preserves control whitespace as translatable content', () =>
    {
        expect(hasTranslatableText('\n\t')).toBe(true);
    });
});
