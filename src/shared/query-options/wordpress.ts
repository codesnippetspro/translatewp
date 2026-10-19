import { queryOptions } from "@tanstack/react-query";
import { getWordPressPostTypes, getWordPressCategories, getWordPressLexiconCategories, getWordPressLexiconTerms, getWordPressRoles } from "@/shared/api/wordpress";

export const getWordPressPostTypesQueryOptions = queryOptions({
    queryKey: ['getWordPressPostTypes'],
    queryFn: async () => {
        const postTypes = Object.values(await getWordPressPostTypes() as any[]);

        return postTypes.map(postType => ({
            label: postType.label,
            value: postType.name ?? postType.slug,
        })) as { label: string; value: string }[];
    }
});

export const getWordPressRolesQueryOptions = queryOptions({
    queryKey: ['getWordPressRoles'],
    queryFn: async () => {
        const roles = await getWordPressRoles() as Record<string, string>;

        return Object.entries(roles).map(([value, label]) => ({ label, value })) as { label: string; value: string }[];
    }
});

export const getWordPressCategoriesQueryOptions = queryOptions({
    queryKey: ['getWordPressCategories'],
    queryFn: async () => {
        const categories = await getWordPressCategories() as any[];

        return categories.map((category: { id: number; name: string }) => ({
            label: category.name,
            value: String(category.id)
        })) as { label: string; value: string }[];
    }
});

export const getWordPressLexiconCategoriesQueryOptions = queryOptions({
    queryKey: ['getWordPressLexiconCategories'],
    queryFn: async () => {
        const categories = await getWordPressLexiconCategories() as any[];

        return categories.map(category => ({
            label: category.name,
            value: String(category.id)
        })) as { label: string; value: string }[];
    }
});

export const getWordPressLexiconTermsQueryOptions = (per_page: number = 10, page: number = 1) => queryOptions({
    queryKey: ['getWordPressLexiconTerms', per_page, page],
    queryFn: () => getWordPressLexiconTerms(per_page, page)
});