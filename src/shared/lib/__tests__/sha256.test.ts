import { TextEncoder } from 'util';
import { webcrypto } from 'crypto';

import { sha256Bytes, sha256Hex } from '@/shared/lib/sha256';

if (typeof globalThis.TextEncoder === 'undefined')
{
    globalThis.TextEncoder = TextEncoder as typeof globalThis.TextEncoder;
}

if (!globalThis.crypto?.subtle)
{
    Object.defineProperty(globalThis, 'crypto', {
        value: webcrypto,
        configurable: true,
    });
}

function encode(value: string): Uint8Array
{
    return new TextEncoder().encode(value);
}

async function subtleSha256Hex(message: Uint8Array): Promise<string>
{
    const digest = await globalThis.crypto.subtle.digest('SHA-256', message);

    return Array.from(new Uint8Array(digest))
        .map(byte => byte.toString(16).padStart(2, '0'))
        .join('');
}

describe('sha256', () =>
{
    it('matches known SHA-256 vectors', () =>
    {
        expect(sha256Hex(encode(''))).toBe(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
        );
        expect(sha256Hex(encode('abc'))).toBe(
            'ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad'
        );
        expect(sha256Hex(encode('abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq'))).toBe(
            '248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1'
        );
    });

    it('returns 32 digest bytes for empty input', () =>
    {
        expect(sha256Bytes(new Uint8Array())).toHaveLength(32);
    });

    it('matches SubtleCrypto output byte-for-byte', async () =>
    {
        const samples = [
            new Uint8Array(),
            encode('abc'),
            encode('translatewp fingerprint payload'),
            encode('{"contextKey":"main > p","contextKind":"text-flow"}'),
        ];

        for (const sample of samples)
        {
            expect(sha256Hex(sample)).toBe(await subtleSha256Hex(sample));
        }
    });
});
