import { useEffect, useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { getWordPressLexiconTermsQueryOptions } from '@/shared/query-options/wordpress';
import { deleteWordPressLexiconTerm, type LexiconTerm } from '@/shared/api/wordpress';
import { applyLexiconNowMutationOptions } from '@/shared/mutation-options/admin';
import { getStaleTranslations } from '@/shared/api/admin';
import { getQuota } from '@/shared/api/quota';
import { quotaCostSentence } from '@/shared/lib/retranslationScope';
import { getLanguageNameFromLocale } from '@/shared/lib/languages';
import { ConfirmDialog } from '@/shared/components/confirmDialog';
import { useConfirmDialog } from '@/shared/components/confirmDialog/useConfirmDialog';

import { LexiconTable, type LexiconRow } from '@/apps/admin/components/Lexicon/LexiconTable';
import { AddLexiconDrawer, type LexiconDrawerPrefill } from '@/apps/admin/components/Lexicon/AddLexiconDrawer';
import { LexiconCandidates, type CandidatePrefill } from '@/apps/admin/components/Lexicon/Candidates';
import { actOnLexiconCandidate } from '@/shared/api/admin';
import { PAGE_INSET, PageBody, PageHeader } from '@/apps/admin/components/PageLayout';
import { managerCan } from '@/shared/lib/access';

export const Lexicon = () =>
{
    const queryClient = useQueryClient();
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [prefill, setPrefill] = useState<(LexiconDrawerPrefill & { candidateId: string }) | null>(null);
    const [drawerContext, setDrawerContext] = useState<'add' | 'edit'>('add');
    const [selectedTerm, setSelectedTerm] = useState<LexiconTerm | null>(null);

    // The Dashboard's empty Lexicon card links here with #add to open the drawer at once.
    useEffect(() =>
    {
        if (window.location.hash !== '#add') return;
        window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
        setDrawerContext('add');
        setSelectedTerm(null);
        setIsDrawerOpen(true);
    }, []);
    const [page, setPage] = useState(1);
    const perPage = 10;
    const [applyNotice, setApplyNotice] = useState<string | null>(null);
    const { requestConfirmation, confirmDialogProps } = useConfirmDialog();

    const { data: lexiconTermsResponse, isFetching } = useQuery(getWordPressLexiconTermsQueryOptions(perPage, page));

    // A term change never marks anything stale by itself. The pages
    // translated under the previous terms are counted here and retranslated
    // only when the owner asks, behind the cost figure.
    const { data: stale } = useQuery({
        queryKey: ['getStaleTranslations'],
        queryFn: getStaleTranslations,
        refetchInterval: 30000,
    });
    const staleLexicon = stale?.lexicon ?? {};
    const stalePages = Object.values(staleLexicon).reduce((sum, count) => sum + count, 0);
    const staleLocales = Object.keys(staleLexicon);
    // Three names read; past that the count says more than a list would.
    const staleLanguages = staleLocales.length > 3
        /* translators: %d: number of languages. */
        ? sprintf(_n('%d language', '%d languages', staleLocales.length, 'translate'), staleLocales.length)
        : staleLocales.map(locale => getLanguageNameFromLocale(locale)).join(', ');

    const applyLexiconMutation = useMutation({
        ...applyLexiconNowMutationOptions(),
        onSuccess: async (result) => {
            setApplyNotice(
                result.requeued > 0
                    /* translators: %s: number of pages. */
                    ? sprintf(__('%s pages are being translated again with the current terms.', 'translate'), result.requeued.toLocaleString())
                    : __('Every translated page already uses the current terms.', 'translate')
            );
            await queryClient.invalidateQueries({ queryKey: ['getStaleTranslations'] });
        },
        onError: () => {
            setApplyNotice(__('The retranslation could not be queued. Try again in a moment.', 'translate'));
        },
    });

    const handleApplyLexicon = async () =>
    {
        setApplyNotice(null);
        const quota = await getQuota().catch(() => null);
        const message = sprintf(
            /* translators: 1: number of pages, 2: language names, 3: the quota sentence. */
            __('%1$s pages in %2$s were translated before the latest lexicon change. %3$s', 'translate'),
            stalePages.toLocaleString(),
            staleLanguages,
            quotaCostSentence({ pages: stalePages, languages: 1 }, quota)
        );
        const confirmed = await requestConfirmation({
            title: __('Retranslate with the current terms?', 'translate'),
            message,
            confirmLabel: __('Retranslate', 'translate'),
            tone: 'proceed',
        });
        if (confirmed) {
            applyLexiconMutation.mutate();
        }
    };

    const { mutate: deleteLexiconTerm, isPending: isDeleting } = useMutation({
        mutationFn: (id: number) => deleteWordPressLexiconTerm(id),
        onSuccess: async (_data, deletedId) => {
            if (selectedTerm?.id === deletedId) {
                setSelectedTerm(null);
                setIsDrawerOpen(false);
            }

            const itemsOnPage = lexiconTermsResponse?.items?.length ?? 0;
            if (itemsOnPage <= 1 && page > 1) {
                setPage((current) => Math.max(1, current - 1));
            }

            await queryClient.invalidateQueries({ queryKey: ['getWordPressLexiconTerms'] });
        }
    });

    const rows: LexiconRow[] = (lexiconTermsResponse?.items ?? []).map((item) => ({
        id: item.id,
        term: item.term,
        languages: item.languages,
        categories: item.categories.map((category) => category.name)
    }));

    const pagination = lexiconTermsResponse?.pagination;

    const handleDelete = async (id: number | string) =>
    {
        if (isDeleting) {
            return;
        }

        const term = rows.find((row) => row.id === Number(id))?.term ?? '';

        const confirmed = await requestConfirmation({
            /* translators: %s: lexicon term. */
            title: sprintf(__("Delete the term '%s'?", 'translate'), term),
            message: __('This removes the term and its translations from your lexicon.', 'translate'),
            confirmLabel: __('Delete', 'translate'),
            isDestructive: true,
        });

        if (!confirmed) {
            return;
        }

        deleteLexiconTerm(Number(id));
    };

    return (
        <div className="flex flex-col">
            <PageHeader
                title="Lexicon"
                description="Manage the lexicon of your website"
                actions={(
                    <>
                        <Button variant='primary' className='rounded-sm! w-fit' onClick={() => {
                            setDrawerContext('add');
                            setSelectedTerm(null);
                            setIsDrawerOpen(true);
                        }}>Add term</Button>
                        {stalePages > 0 && managerCan().retranslate && (
                            <Button
                                variant='secondary'
                                className='rounded-sm! w-fit'
                                disabled={applyLexiconMutation.isPending}
                                isBusy={applyLexiconMutation.isPending}
                                onClick={() => { void handleApplyLexicon(); }}
                            >
                                {applyLexiconMutation.isPending ? __('Queueing…', 'translate') : __('Retranslate with current terms', 'translate')}
                            </Button>
                        )}
                    </>
                )}
            />

            {stalePages > 0 && !applyNotice && (
                <p className={`-mt-2 text-sm text-translatewp-muted ${PAGE_INSET}`} role="status">
                    {sprintf(
                        /* translators: 1: number of pages, 2: language names. */
                        __('%1$s translated pages in %2$s still use the previous terms. New visits keep the stored translation until you retranslate.', 'translate'),
                        stalePages.toLocaleString(),
                        staleLanguages
                    )}
                </p>
            )}

            {applyNotice && (
                <p className={`-mt-2 text-sm text-translatewp-muted ${PAGE_INSET}`} role="status">{applyNotice}</p>
            )}

            <PageBody>
                <LexiconCandidates onAdd={(proposal: CandidatePrefill) => {
                    setPrefill(proposal);
                    setDrawerContext('add');
                    setSelectedTerm(null);
                    setIsDrawerOpen(true);
                }} />

                <LexiconTable
                    rows={rows}
                    onAdd={() => {
                        setDrawerContext('add');
                        setSelectedTerm(null);
                        setIsDrawerOpen(true);
                    }}
                    onEdit={(id) => {
                        const termToEdit = (lexiconTermsResponse?.items ?? []).find((item) => item.id === Number(id)) ?? null;
                        setSelectedTerm(termToEdit);
                        setDrawerContext('edit');
                        setIsDrawerOpen(true);
                    }}
                    onDelete={handleDelete}
                />

                {pagination && (
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <span className="text-sm text-translatewp-muted!">
                            Page {pagination.page} of {Math.max(1, pagination.total_pages)} ({pagination.total} terms)
                        </span>

                        <div className="flex items-center gap-2">
                            <Button
                                variant='secondary'
                                className='rounded-sm! min-w-20! justify-center'
                                disabled={!pagination.has_prev || isFetching}
                                onClick={() => setPage((current) => Math.max(1, current - 1))}
                            >
                                Previous
                            </Button>
                            <Button
                                variant='secondary'
                                className='rounded-sm! min-w-20! justify-center'
                                disabled={!pagination.has_next || isFetching}
                                onClick={() => setPage((current) => current + 1)}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </PageBody>

            <AddLexiconDrawer
                isDrawerOpen={isDrawerOpen}
                setIsDrawerOpen={(open) => { setIsDrawerOpen(open); if (!open) setPrefill(null); }}
                prefill={prefill}
                onSaved={() => {
                    if (!prefill) return;
                    const candidateId = prefill.candidateId;
                    setPrefill(null);
                    void actOnLexiconCandidate(candidateId, 'add').then(() => queryClient.invalidateQueries({ queryKey: ['lexiconCandidates'] }));
                }}
                drawerContext={drawerContext}
                editingTerm={selectedTerm}
            />

            <ConfirmDialog {...confirmDialogProps} />
        </div>
    );
}
