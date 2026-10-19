import { __, _n, sprintf } from '@wordpress/i18n';

/** "45 s" under a minute, "2 min 45 s" once a run runs a minute or longer. */
export const formatElapsed = (elapsedMs: number): string =>
{
    const totalSeconds = Math.max(0, Math.round(elapsedMs / 1000));
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (minutes === 0) {
        /* translators: %d: number of seconds. */
        return sprintf(__('%d s', 'translate'), seconds);
    }

    return sprintf(
        /* translators: 1: number of minutes, 2: number of seconds. */
        __('%1$d min %2$d s', 'translate'),
        minutes,
        seconds
    );
};

/** "N pages translated into M languages in T" once the first run finishes. */
export const completionSummaryText = ({ pages, languages, elapsedMs }: { pages: number; languages: number; elapsedMs: number }): string =>
    sprintf(
        /* translators: 1: "N pages", 2: "N languages", 3: elapsed time, e.g. "2 min 45 s". */
        __('%1$s translated into %2$s in %3$s', 'translate'),
        sprintf(_n('%d page', '%d pages', pages, 'translate'), pages),
        sprintf(_n('%d language', '%d languages', languages, 'translate'), languages),
        formatElapsed(elapsedMs)
    );

export const HUMAN_HOURS_PER_PAGE_TRANSLATION = 2.5;
const HOURS_PER_WORKING_DAY = 8;
const DAYS_PER_WORKING_WEEK = 5;

/** Whole working hours as "4 weeks, 2 days and 4 hours", leaving out any unit that is zero. */
export const formatWorkingTime = (hours: number): string =>
{
    const total = Math.max(0, Math.round(hours));
    const weeks = Math.floor(total / (HOURS_PER_WORKING_DAY * DAYS_PER_WORKING_WEEK));
    const days = Math.floor((total % (HOURS_PER_WORKING_DAY * DAYS_PER_WORKING_WEEK)) / HOURS_PER_WORKING_DAY);
    const rest = total % HOURS_PER_WORKING_DAY;
    const parts = [
        weeks > 0 ? sprintf(_n('%s week', '%s weeks', weeks, 'translate'), weeks.toLocaleString()) : '',
        days > 0 ? sprintf(_n('%d day', '%d days', days, 'translate'), days) : '',
        rest > 0 || total === 0 ? sprintf(_n('%d hour', '%d hours', rest, 'translate'), rest) : '',
    ].filter(Boolean);

    if (parts.length === 3) {
        /* translators: 1: weeks, 2: days, 3: hours, e.g. "4 weeks", "2 days", "4 hours". */
        return sprintf(__('%1$s, %2$s and %3$s', 'translate'), parts[0], parts[1], parts[2]);
    }
    if (parts.length === 2) {
        /* translators: two of weeks, days and hours, e.g. "1 day" and "4 hours". */
        return sprintf(__('%1$s and %2$s', 'translate'), parts[0], parts[1]);
    }
    return parts[0];
};

/** "A translator would need about 8 weeks, 2 days and 2 hours for the same work; you saved about …" */
export const humanComparisonText = ({ pages, languages, elapsedMs }: { pages: number; languages: number; elapsedMs: number }): string =>
{
    const hours = pages * languages * HUMAN_HOURS_PER_PAGE_TRANSLATION;
    if (hours <= 0) return '';
    const saved = Math.max(0, hours - elapsedMs / 3_600_000);

    return sprintf(
        /* translators: 1: translator time, e.g. "4 weeks, 2 days and 4 hours", 2: time saved in the same form. */
        __('A translator would need about %1$s for the same work; you saved about %2$s.', 'translate'),
        formatWorkingTime(hours),
        formatWorkingTime(saved)
    );
};

/**
 * While the run is on, two lines: the progress with its time, and the
 * translator comparison for what is ready so far. The second is empty
 * before anything is ready.
 */
export const progressLines = ({ ready, total, pages, languages, elapsedMs }: { ready: number; total: number; pages: number; languages: number; elapsedMs: number }): [string, string] =>
{
    const scope = sprintf(
        /* translators: 1: "N pages", 2: "N languages". */
        __('%1$s in %2$s', 'translate'),
        sprintf(_n('%d page', '%d pages', pages, 'translate'), pages),
        sprintf(_n('%d language', '%d languages', languages, 'translate'), languages)
    );
    const status = sprintf(
        /* translators: 1: page translations ready, 2: page translations in total, 3: elapsed time, 4: "N pages in M languages". */
        __('%1$d of %2$d page translations ready after %3$s (%4$s)', 'translate'),
        ready,
        total,
        formatElapsed(elapsedMs),
        scope
    );
    const comparison = ready > 0
        ? sprintf(
            /* translators: %s: translator time, e.g. "1 week, 3 days and 4 hours". */
            __('A translator would need about %s for those.', 'translate'),
            formatWorkingTime(ready * HUMAN_HOURS_PER_PAGE_TRANSLATION)
        )
        : '';

    return [status, comparison];
};
