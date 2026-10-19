/**
 * A read proposes the brief: it takes the field over, whatever an earlier
 * read or edit left there, so two briefs never stack. The owner edits the
 * proposal afterwards; a read that found nothing leaves the field alone.
 */
export const mergeContext = (existing: string, lastProposed: string, proposed: string): string =>
{
    void lastProposed;
    const next = proposed.trim();

    return next === '' ? existing : next;
};
