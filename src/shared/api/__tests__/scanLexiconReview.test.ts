import apiFetch from '@wordpress/api-fetch';
import { applyScanLexicon } from '@/shared/api/setup';

jest.mock('@wordpress/api-fetch', () => jest.fn());
const request = jest.mocked(apiFetch);

beforeEach(() => { request.mockReset().mockResolvedValue({ added: 1 }); });

it.each([
    [undefined, '{"path":"translatewp/v1/scanning/context/apply-lexicon","method":"POST","data":{}}'],
    [[], '{"path":"translatewp/v1/scanning/context/apply-lexicon","method":"POST","data":{"terms":[]}}'],
    [['Quillforge'], '{"path":"translatewp/v1/scanning/context/apply-lexicon","method":"POST","data":{"terms":["Quillforge"]}}'],
])('keeps the default Settings request byte for byte for %s', async (terms, expected) =>
{
    await applyScanLexicon(terms as string[] | undefined);
    expect(request).toHaveBeenCalledTimes(1);
    expect(JSON.stringify(request.mock.calls[0][0])).toBe(expected);
});

it.each(['add', 'dismiss'] as const)('sends only named rows for partial %s', async action =>
{
    await applyScanLexicon(['Quillforge'], action);
    expect(JSON.stringify(request.mock.calls[0][0])).toBe(
        `{"path":"translatewp/v1/scanning/context/apply-lexicon","method":"POST","data":{"terms":["Quillforge"],"action":"${action}","preserve_unselected":true}}`
    );
});
