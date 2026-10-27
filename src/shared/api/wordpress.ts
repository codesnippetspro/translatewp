import apiFetch from '@wordpress/api-fetch';
import type { FloatingSwitcherPosition, SideTabPosition, SwitcherDevice } from '@/shared/lib/switcherPosition';

export interface LexiconCategory
{
    id: number;
    name: string;
    slug: string;
}

export interface LexiconTerm
{
    id: number;
    term: string;
    description: string;
    languages: string[];
    categories: LexiconCategory[];
}

export interface LexiconTermsPagination
{
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
    has_next: boolean;
    has_prev: boolean;
}

export interface LexiconTermsResponse
{
    items: LexiconTerm[];
    pagination: LexiconTermsPagination;
}

export const getWordPressPostTypes = async () =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/get-post-types',
        method: 'GET'
    });
};

export const getWordPressRoles = async () =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/get-roles',
        method: 'GET'
    });
};

export const getWordPressCategories = async () =>
{
    return await apiFetch({
        path: '/wp/v2/categories?per_page=100&hide_empty=false',
        method: 'GET'
    });
};

export const getWordPressLexiconCategories = async () =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/get-lexicon-categories',
        method: 'GET'
    });
};

export const createWordPressLexiconTerm = async (term: string, description: string, languages: string[], categories: string[], newCategories: string[]) =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/create-lexicon-term',
        method: 'POST',
        data: {
            term,
            description,
            languages,
            categories,
            new_categories: newCategories
        }
    });
};

export const updateWordPressLexiconTerm = async (id: number, term: string, description: string, languages: string[], categories: string[], newCategories: string[]) =>
{
    return await apiFetch({
        path: '/translatewp/v1/admin/update-lexicon-term',
        method: 'POST',
        data: {
            id,
            term,
            description,
            languages,
            categories,
            new_categories: newCategories
        }
    });
};

export const deleteWordPressLexiconTerm = async (id: number) =>
{
    return await apiFetch<{ id: number; success: boolean }>({
        path: '/translatewp/v1/admin/delete-lexicon-term',
        method: 'POST',
        data: { id }
    });
};

export const getWordPressLexiconTerms = async (per_page: number = 10, page: number = 1) =>
{
    return await apiFetch<LexiconTermsResponse>({
        path: `/translatewp/v1/admin/get-lexicon-terms?per_page=${per_page}&page=${page}`,
        method: 'GET'
    });
};

/** The server stores the place of the type the site renders and refuses a kind that does not match it. */
export type SaveSwitcherPositionInput = { device?: SwitcherDevice; revision?: number } & (
	| { kind?: 'floating'; position: FloatingSwitcherPosition }
	| { kind: 'side_tab'; position: SideTabPosition }
);
export type SaveSwitcherPositionResult = {
	success: true;
	position: FloatingSwitcherPosition | SideTabPosition;
	revision: number;
};

export const saveSwitcherPosition = (input: SaveSwitcherPositionInput) =>
	apiFetch<SaveSwitcherPositionResult>({
		path: '/translatewp/v1/admin/switcher-position',
		method: 'POST',
		headers: { 'X-WP-Nonce': window.TranslateWP.context.nonce },
		data: input,
	});
