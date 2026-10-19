import { runHydration } from '@/shared/lib/translations/hydrationScheduler';
import type { HydrationStore } from '@/shared/lib/translations/hydrationStore';
import type { TranslationCacheClient } from '@/shared/lib/translations/TranslationCacheClient';

describe('runHydration language scoping', () =>
{
    beforeEach(() =>
    {
        document.body.innerHTML = '<p>Hello world</p>';
        ( window as unknown as { TranslateWP: unknown } ).TranslateWP = {
            context: { site_id: 'site', remote_api_base: 'https://api.example' },
            options: { ai_settings: {} },
        };
    });

    it('reads and writes the hydration cache under the language it is prefetching', async () =>
    {
        // A prefetch for German must key its store reads and writes by 'de', or
        // its entries land under a generation the visitor path never reads and
        // only waste the storage budget.
        const getManyLanguages: Array<string | undefined> = [];
        const putManyLanguages: Array<string | undefined> = [];
        const store = {
            recentLanguages: () => [],
            getMany: async ( _hashes: string[], language?: string ) => { getManyLanguages.push( language ); return new Map<string, string>(); },
            putMany: async ( _entries: Map<string, string>, language?: string ) => { putManyLanguages.push( language ); },
        } as unknown as HydrationStore;

        const client = {
            prepare: async () => ( { groupsForCache: [ { hash: 'h1', markerPayload: '⟪t⟫Hello', group: { id: 'g1' } } ] } ),
            lookup: async () => ( { status: 'available', entries: new Map( [ [ 'h1', 'Hallo' ] ] ) } ),
        } as unknown as TranslationCacheClient;

        await runHydration( {
            root: document.body,
            sourceLang: 'en',
            activeLang: 'de',
            configuredLangs: [ 'de' ],
            store,
            createClient: () => client,
        } );

        expect( getManyLanguages ).toEqual( [ 'de' ] );
        expect( putManyLanguages ).toEqual( [ 'de' ] );
    } );
} );
